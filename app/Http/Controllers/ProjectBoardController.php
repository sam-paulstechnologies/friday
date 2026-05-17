<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use Inertia\Inertia;
use Inertia\Response;

class ProjectBoardController extends Controller
{
    public function __invoke(Project $project): Response
    {
        $project->load(['workspace:id,name']);

        $tasks = Task::query()
            ->with(['assignee:id,name'])
            ->where('project_id', $project->id)
            ->whereIn('status', Task::BOARD_STATUSES)
            ->orderBy('position')
            ->latest()
            ->get()
            ->groupBy('status')
            ->map(fn ($tasks) => $tasks->map(fn (Task $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'section' => $task->section,
                'status' => $task->status,
                'priority' => $task->priority,
                'due_date' => $task->due_date?->toDateString(),
                'assignee' => $task->assignee ? [
                    'id' => $task->assignee->id,
                    'name' => $task->assignee->name,
                ] : null,
            ])->values());

        return Inertia::render('Projects/Board', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'workspace' => $project->workspace ? [
                    'id' => $project->workspace->id,
                    'name' => $project->workspace->name,
                ] : null,
            ],
            'columns' => collect(Task::BOARD_STATUSES)
                ->mapWithKeys(fn (string $status) => [$status => $tasks->get($status, collect())->values()])
                ->all(),
            'statuses' => Task::BOARD_STATUSES,
        ]);
    }
}
