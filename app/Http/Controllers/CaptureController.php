<?php

namespace App\Http\Controllers;

use App\Services\Inbox\CaptureFailedException;
use App\Services\Inbox\WebCaptureService;
use App\Services\Tasks\InvalidTaskTransitionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Quick Capture — the web counterpart to Slack thought capture.
 *
 * Both entry points land in the same Inbox through the same capture domain.
 */
class CaptureController extends Controller
{
    public function __construct(private readonly WebCaptureService $capture) {}

    public function store(Request $request, WebCaptureService $capture): RedirectResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:5000'],
            'destination' => ['nullable', Rule::in(WebCaptureService::DESTINATIONS)],
            // Per-submission token so a double click or a replayed POST
            // resolves to the same capture instead of a second one.
            'client_token' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $result = $capture->capture(
                $request->user(),
                $data['text'],
                $data['destination'] ?? WebCaptureService::DESTINATION_INBOX,
                $data['client_token'] ?? null,
            );
        } catch (CaptureFailedException|InvalidTaskTransitionException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $task = $result['task'];
        $toToday = ($data['destination'] ?? null) === WebCaptureService::DESTINATION_TODAY;

        if (! $result['created']) {
            return back()->with('capture', [
                'status' => 'duplicate',
                'message' => 'That was already captured a moment ago.',
                'task_id' => $task->id,
                'url' => route('inbox.show', ['task', $task->id]),
            ]);
        }

        return back()->with('capture', [
            'status' => $result['classified'] ? 'captured' : 'needs_review',
            'message' => $toToday
                ? 'Added to Today.'
                : ($result['classified']
                    ? 'Captured to your Inbox.'
                    : 'Captured to your Inbox. Miriam could not read any details, so it is marked for review.'),
            'task_id' => $task->id,
            'title' => $task->title,
            'url' => $toToday
                ? route('tasks.show', $task->id)
                : route('inbox.show', ['task', $task->id]),
        ]);
    }
}
