<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Services\DailyReview\DailyReviewService;
use App\Services\Spiritual\SpiritualReadingSummaryService;
use App\Services\TodayCommandCenterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TodayController extends Controller
{
    public function index(
        Request $request,
        DailyReviewService $dailyReviewService,
        SpiritualReadingSummaryService $spiritualReadingSummaryService,
        TodayCommandCenterService $commandCenter
    ): Response
    {
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
                'morning' => 'Critical, overdue, waiting, Codex, medication, product focus, and backlog are separated for fast triage.',
                'evening' => 'Completed work stays separate; unresolved overdue and blocked work remains visible until cleared.',
            ],
            'reading' => $spiritualReadingSummaryService->forUser($request->user()),
            'commandCenter' => $commandCenter->forUser($request->user()),
        ]);
    }

    public function tomorrow(Request $request, Task $task)
    {
        Gate::authorize('update', $task);

        $task->update(['due_date' => now()->addDay()->toDateString()]);
        $task->comments()->create([
            'user_id' => $request->user()->id,
            'body' => 'Moved to tomorrow from Today Command Center.',
        ]);

        return back()->with('success', 'Task moved to tomorrow.');
    }

    public function today(Request $request, Task $task)
    {
        Gate::authorize('update', $task);

        $task->update(['due_date' => now()->toDateString()]);
        $task->comments()->create([
            'user_id' => $request->user()->id,
            'body' => 'Moved to today from My Day.',
        ]);

        return back()->with('success', 'Task moved to today.');
    }

    public function snooze(Request $request, Task $task)
    {
        Gate::authorize('update', $task);

        $task->update(['due_date' => now()->addDays(3)->toDateString()]);
        $task->comments()->create([
            'user_id' => $request->user()->id,
            'body' => 'Snoozed for 3 days from My Day.',
        ]);

        return back()->with('success', 'Task snoozed.');
    }

    private function taskResource(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'priority' => $task->priority,
            'due_date' => $task->due_date?->toDateString(),
            'start_date' => $task->start_date?->toDateString(),
            'completed_at' => $task->completed_at?->toDateTimeString(),
            'missed_yesterday' => $task->due_date?->toDateString() === now()->subDay()->toDateString(),
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
        ];
    }
}
