<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Services\DailyReview\DailyReviewService;
use App\Services\Inbox\InboxService;
use App\Services\MiriamReminderService;
use App\Services\Spiritual\SpiritualReadingSummaryService;
use App\Services\Tasks\InvalidTaskTransitionException;
use App\Services\Tasks\TaskTransitionService;
use App\Services\TodayCommandCenterService;
use App\Support\OperationalClock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TodayController extends Controller
{
    public function __construct(
        private readonly OperationalClock $clock,
        private readonly TaskTransitionService $transitions,
    ) {}

    public function index(
        Request $request,
        DailyReviewService $dailyReviewService,
        SpiritualReadingSummaryService $spiritualReadingSummaryService,
        TodayCommandCenterService $commandCenter,
        InboxService $inbox,
    ): Response {
        $groups = $dailyReviewService->collectTodayTasks($request->user());
        $focus = $dailyReviewService->selectTopFocusItems($groups);

        return Inertia::render('Today/Index', [
            'groups' => $groups->map(fn ($tasks) => $tasks->map(fn (Task $task) => $this->taskResource($task))->values()),
            'focus' => $focus->map(fn (Task $task) => $this->taskResource($task))->values(),
            'summary' => [
                'overdue' => $groups->get('overdue')->count(),
                'missed_yesterday' => $groups->get('missed_yesterday')->count(),
                'due_today' => $groups->get('due_today')->count(),
                'scheduled_today' => $groups->get('scheduled_today')->count(),
                'upcoming' => $groups->get('upcoming')->count(),
                'completed_today' => $groups->get('completed_today')->count(),
                'blocked_waiting' => $groups->get('blocked')->count() + $groups->get('waiting')->count(),
            ],
            'dailyReview' => [
                'morning' => 'Critical, overdue, waiting, medication, and reminders are separated for fast triage.',
                'evening' => 'Completed work stays separate; unresolved overdue and blocked work remains visible until cleared.',
            ],
            'today' => [
                'date' => $this->clock->todayString(),
                'timezone' => $this->clock->timezone(),
                'label' => $this->clock->now()->format('l, M j'),
            ],
            'inboxCount' => $inbox->openCount($request->user()),
            'reading' => $spiritualReadingSummaryService->forUser($request->user()),
            'commandCenter' => $commandCenter->forUser($request->user()),
        ]);
    }

    public function tomorrow(Request $request, Task $task)
    {
        Gate::authorize('update', $task);

        $task->update(['due_date' => $this->clock->tomorrowString()]);
        $task->comments()->create([
            'user_id' => $request->user()->id,
            'body' => 'Moved to tomorrow from Today.',
        ]);
        app(MiriamReminderService::class)->syncAfterTaskSaved($task->refresh(), $request->user(), true);

        return back()->with('success', 'Task moved to tomorrow.');
    }

    public function today(Request $request, Task $task)
    {
        return $this->transition($request, $task, TaskTransitionService::MOVE_TODAY, 'Task moved to today.');
    }

    public function snooze(Request $request, Task $task)
    {
        Gate::authorize('update', $task);

        $task->update(['due_date' => $this->clock->dateString(3)]);
        $task->comments()->create([
            'user_id' => $request->user()->id,
            'body' => 'Snoozed for 3 days from Today.',
        ]);
        app(MiriamReminderService::class)->syncAfterTaskSaved($task->refresh(), $request->user(), true);

        return back()->with('success', 'Task snoozed.');
    }

    public function later(Request $request, Task $task)
    {
        return $this->transition($request, $task, TaskTransitionService::MOVE_LATER, 'Task moved to Later.');
    }

    public function waiting(Request $request, Task $task)
    {
        return $this->transition($request, $task, TaskTransitionService::MOVE_WAITING, 'Task marked as waiting.');
    }

    /**
     * All Today buttons go through the one transition service, so a failure
     * surfaces as an error instead of a UI that reports success anyway.
     */
    private function transition(Request $request, Task $task, string $transition, string $message)
    {
        try {
            $this->transitions->apply($task, $transition, $request->user(), ['source' => 'today']);
        } catch (InvalidTaskTransitionException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $message);
    }

    private function taskResource(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'section' => $task->section,
            'priority' => $task->priority,
            'due_date' => $task->due_date?->toDateString(),
            'start_date' => $task->start_date?->toDateString(),
            'completed_at' => $task->completed_at?->toDateTimeString(),
            'missed_yesterday' => $task->due_date?->toDateString() === $this->clock->yesterdayString(),
            'workspace' => $task->workspace ? [
                'id' => $task->workspace->id,
                'name' => $task->workspace->name,
            ] : null,
            'project' => $task->project ? [
                'id' => $task->project->id,
                'name' => $task->project->name,
            ] : null,
            'area' => $task->area ? [
                'id' => $task->area->id,
                'name' => $task->area->name,
            ] : null,
            'portfolio' => $task->portfolio ? [
                'id' => $task->portfolio->id,
                'name' => $task->portfolio->name,
            ] : null,
            'task_type' => $task->task_type,
            'source' => $task->source,
            'source_metadata' => $task->source_metadata ?? [],
        ];
    }
}
