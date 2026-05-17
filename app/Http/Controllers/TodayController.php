<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Services\DailyReview\DailyReviewService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TodayController extends Controller
{
    public function index(Request $request, DailyReviewService $dailyReviewService): Response
    {
        $groups = $dailyReviewService->collectTodayTasks($request->user());
        $focus = $dailyReviewService->selectTopFocusItems($groups);

        return Inertia::render('Today/Index', [
            'groups' => $groups->map(fn ($tasks) => $tasks->map(fn (Task $task) => $this->taskResource($task))->values()),
            'focus' => $focus->map(fn (Task $task) => $this->taskResource($task))->values(),
            'summary' => [
                'overdue' => $groups->get('overdue')->count(),
                'due_today' => $groups->get('due_today')->count(),
                'upcoming' => $groups->get('upcoming')->count(),
                'blocked_waiting' => $groups->get('blocked')->count() + $groups->get('waiting')->count(),
            ],
        ]);
    }

    public function tomorrow(Request $request, Task $task)
    {
        abort_unless($task->assignee_id === $request->user()->id || $task->reporter_id === $request->user()->id, 403);

        $task->update(['due_date' => now()->addDay()->toDateString()]);
        $task->comments()->create([
            'user_id' => $request->user()->id,
            'body' => 'Moved to tomorrow from Today Command Center.',
        ]);

        return back()->with('success', 'Task moved to tomorrow.');
    }

    private function taskResource(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'priority' => $task->priority,
            'due_date' => $task->due_date?->toDateString(),
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
