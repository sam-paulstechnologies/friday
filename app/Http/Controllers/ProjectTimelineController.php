<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectTimelineController extends Controller
{
    public function __invoke(Project $project): Response
    {
        Gate::authorize('view', $project);

        $project->load(['workspace:id,name', 'tasks' => fn ($query) => $query
            ->with('assignee:id,name')
            ->where('status', '!=', 'archived')
            ->orderBy('start_date')
            ->orderBy('due_date')
            ->orderBy('position')]);

        $datedTasks = $project->tasks->filter(fn (Task $task) => $task->start_date || $task->due_date)->values();
        $unscheduledTasks = $project->tasks->reject(fn (Task $task) => $task->start_date || $task->due_date)->values();
        [$rangeStart, $rangeEnd] = $this->dateRange($project, $datedTasks);
        $weeks = $this->weeks($rangeStart, $rangeEnd);

        return Inertia::render('Projects/Timeline', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'start_date' => $project->start_date?->toDateString(),
                'due_date' => $project->due_date?->toDateString(),
                'workspace' => $project->workspace ? [
                    'id' => $project->workspace->id,
                    'name' => $project->workspace->name,
                ] : null,
            ],
            'range' => [
                'start' => $rangeStart?->toDateString(),
                'end' => $rangeEnd?->toDateString(),
            ],
            'weeks' => $weeks,
            'tasks' => $datedTasks->map(fn (Task $task) => $this->taskResource($task, $rangeStart, $rangeEnd))->values(),
            'unscheduledTasks' => $unscheduledTasks->map(fn (Task $task) => $this->taskResource($task, $rangeStart, $rangeEnd))->values(),
        ]);
    }

    private function dateRange(Project $project, Collection $tasks): array
    {
        $dates = collect([
            $project->start_date,
            $project->due_date,
            ...$tasks->flatMap(fn (Task $task) => [$task->start_date, $task->due_date])->all(),
        ])->filter();

        if ($dates->isEmpty()) {
            return [null, null];
        }

        $start = CarbonImmutable::parse($dates->min())->startOfWeek();
        $end = CarbonImmutable::parse($dates->max())->endOfWeek();

        if ($start->equalTo($end) || $start->greaterThan($end)) {
            $end = $start->addWeek()->endOfWeek();
        }

        return [$start, $end];
    }

    private function weeks(?CarbonImmutable $start, ?CarbonImmutable $end): array
    {
        if (! $start || ! $end) {
            return [];
        }

        $weeks = [];
        $cursor = $start;

        while ($cursor->lte($end)) {
            $weeks[] = [
                'label' => $cursor->format('M j'),
                'start' => $cursor->toDateString(),
            ];
            $cursor = $cursor->addWeek();
        }

        return $weeks;
    }

    private function taskResource(Task $task, ?CarbonImmutable $rangeStart, ?CarbonImmutable $rangeEnd): array
    {
        $start = $task->start_date ?: $task->due_date;
        $end = $task->due_date ?: $task->start_date;
        $left = 0;
        $width = 0;

        if ($start && $end && $rangeStart && $rangeEnd) {
            $totalDays = max(1, $rangeStart->diffInDays($rangeEnd));
            $offsetDays = max(0, $rangeStart->diffInDays(CarbonImmutable::parse($start), false));
            $durationDays = max(1, CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)) + 1);
            $left = min(100, max(0, ($offsetDays / $totalDays) * 100));
            $width = min(100 - $left, max(4, ($durationDays / $totalDays) * 100));
        }

        return [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'priority' => $task->priority,
            'start_date' => $task->start_date?->toDateString(),
            'due_date' => $task->due_date?->toDateString(),
            'section' => $task->section,
            'bar' => [
                'left' => round($left, 2),
                'width' => round($width, 2),
            ],
            'assignee' => $task->assignee ? [
                'id' => $task->assignee->id,
                'name' => $task->assignee->name,
            ] : null,
        ];
    }
}
