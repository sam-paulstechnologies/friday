<?php

namespace App\Services\Inbox;

use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Miriam\MiriamSlackThoughtCaptureService;
use App\Services\Tasks\TaskTransitionService;
use App\Support\OperationalClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Quick Capture from the web application.
 *
 * Slack and the web write the *same* record: a Task in the `inbox` workflow
 * state carrying the untouched original wording. There is one capture domain,
 * one Inbox and one conversion path — the entry point is the only difference.
 *
 * The operator's exact words are persisted before anything is interpreted, so
 * a classifier failure can never lose a thought.
 */
class WebCaptureService
{
    public const SOURCE = 'web_quick_capture';

    public const DESTINATION_INBOX = 'inbox';

    public const DESTINATION_TODAY = 'today';

    public const DESTINATIONS = [self::DESTINATION_INBOX, self::DESTINATION_TODAY];

    public function __construct(
        private readonly MiriamSlackThoughtCaptureService $captureService,
        private readonly TaskTransitionService $transitions,
        private readonly OperationalClock $clock,
    ) {}

    /**
     * @param  string|null  $clientToken  Per-submission token from the form; makes
     *                                    a double-click or replayed POST a no-op.
     * @return array{task: Task, created: bool, classified: bool}
     *
     * @throws CaptureFailedException
     */
    public function capture(User $user, string $text, string $destination = self::DESTINATION_INBOX, ?string $clientToken = null): array
    {
        $original = trim($text);

        if ($original === '') {
            throw CaptureFailedException::empty();
        }

        $destination = in_array($destination, self::DESTINATIONS, true) ? $destination : self::DESTINATION_INBOX;
        $dedupeKey = $this->dedupeKey($user, $original, $clientToken);

        $existing = Task::query()->where('source_dedupe_key', $dedupeKey)->first();

        if ($existing) {
            return ['task' => $existing, 'created' => false, 'classified' => true];
        }

        $workspace = $this->workspaceFor($user);

        if (! $workspace) {
            throw CaptureFailedException::noWorkspace();
        }

        // Interpretation is best-effort and happens outside the write, so a
        // classifier exception cannot roll back the operator's words.
        [$parsed, $classified] = $this->interpret($original, $user, $workspace);

        $task = DB::transaction(function () use ($user, $workspace, $original, $parsed, $classified, $dedupeKey): Task {
            $task = Task::create([
                'workspace_id' => $workspace->id,
                'project_id' => $parsed['project_id'] ?? null,
                'task_type' => $parsed['task_type'] ?? 'task',
                'workflow_state' => Task::WORKFLOW_INBOX,
                'title' => $parsed['title'] ?? $this->fallbackTitle($original),
                'description' => $original,
                'status' => 'todo',
                'priority' => $parsed['priority'] ?? 'medium',
                'assignee_id' => $user->id,
                'reporter_id' => $user->id,
                'due_date' => $parsed['due_date'] ?? null,
                'source' => self::SOURCE,
                'source_dedupe_key' => $dedupeKey,
                'source_metadata' => [
                    'source' => self::SOURCE,
                    'captured_via' => 'web',
                    'original_text' => $original,
                    'captured_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                    'classified' => $classified,
                    'needs_review' => ! $classified,
                    'display_type' => $parsed['display_type'] ?? 'Note',
                    'project_name' => $parsed['project_name'] ?? null,
                    'due_label' => $parsed['due_label'] ?? null,
                    'due_time' => $parsed['due_time'] ?? null,
                    'confidence' => $classified ? ($parsed['confidence'] ?? null) : 0.0,
                ],
            ]);

            $task->activities()->create([
                'user_id' => $user->id,
                'action' => 'capture_received',
                'description' => $classified
                    ? 'Captured from the web. Miriam proposed an interpretation.'
                    : 'Captured from the web. Miriam could not interpret it, so it needs review.',
            ]);

            return $task;
        });

        // "Add to Today" is an explicit user choice, never inferred from the
        // urgency of the wording, and it goes through the canonical service.
        if ($destination === self::DESTINATION_TODAY) {
            $task = $this->transitions->apply($task, TaskTransitionService::MOVE_TODAY, $user, [
                'source' => 'quick_capture',
                'reason' => 'Added straight to Today from Quick Capture.',
            ]);
        }

        return ['task' => $task, 'created' => true, 'classified' => $classified];
    }

    /** @return array{0: array, 1: bool} parsed proposal, and whether it succeeded */
    private function interpret(string $text, User $user, Workspace $workspace): array
    {
        try {
            $parsed = $this->captureService->parseCapture($text, $user, $workspace);
        } catch (Throwable) {
            return [[], false];
        }

        return [[
            'title' => $parsed['title'] ?? null,
            'task_type' => in_array($parsed['task_type'] ?? null, Task::TYPES, true) ? $parsed['task_type'] : 'task',
            'priority' => in_array($parsed['priority'] ?? null, Task::PRIORITIES, true) ? $parsed['priority'] : 'medium',
            // A project only attaches when the parser resolved a real record.
            'project_id' => $parsed['project_id'] ?? null,
            'project_name' => $parsed['project_name'] ?? null,
            'due_date' => $parsed['due_date'] ?? null,
            'due_label' => $parsed['due_label'] ?? null,
            'due_time' => $parsed['due_time'] ?? null,
            'display_type' => $parsed['display_type'] ?? null,
            'confidence' => $parsed['confidence'] ?? null,
        ], true];
    }

    /**
     * Same thought, same key. The client token scopes it to one submission so
     * capturing the identical sentence again tomorrow still works.
     */
    private function dedupeKey(User $user, string $text, ?string $clientToken): string
    {
        $scope = filled($clientToken)
            ? 'token:'.$clientToken
            : 'minute:'.$this->clock->now()->format('Y-m-d H:i');

        return 'web:'.sha1($user->id.'|'.mb_strtolower($text).'|'.$scope);
    }

    private function fallbackTitle(string $text): string
    {
        $firstLine = trim(explode("\n", $text)[0]);

        return mb_strlen($firstLine) > 120 ? mb_substr($firstLine, 0, 117).'...' : $firstLine;
    }

    private function workspaceFor(User $user): ?Workspace
    {
        $workspaceId = collect($user->accessibleWorkspaceIds())
            ->first(fn (int $id): bool => $user->canWriteWorkspace($id));

        return $workspaceId ? Workspace::query()->find($workspaceId) : null;
    }
}
