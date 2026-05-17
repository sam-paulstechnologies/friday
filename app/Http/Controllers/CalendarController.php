<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
    public function index(Request $request): Response
    {
        $month = $request->query('month', now()->format('Y-m'));
        $start = CarbonImmutable::createFromFormat('Y-m-d', "{$month}-01")->startOfMonth();
        $end = $start->endOfMonth();

        $taskEvents = Task::query()
            ->whereNotIn('status', ['archived'])
            ->where(function ($query) use ($start, $end): void {
                $query->whereBetween('start_date', [$start->toDateString(), $end->toDateString()])
                    ->orWhereBetween('due_date', [$start->toDateString(), $end->toDateString()]);
            })
            ->get()
            ->flatMap(function (Task $task) use ($start, $end) {
                $events = collect();

                if ($task->start_date && $task->start_date->betweenIncluded($start, $end)) {
                    $events->push($this->event('task_start', 'Task start', $task->title, $task->start_date->toDateString(), route('tasks.show', $task, false)));
                }

                if ($task->due_date && $task->due_date->betweenIncluded($start, $end)) {
                    $events->push($this->event('task_due', 'Task due', $task->title, $task->due_date->toDateString(), route('tasks.show', $task, false)));
                }

                return $events;
            });

        $projectEvents = Project::query()
            ->where('status', '!=', 'archived')
            ->where(function ($query) use ($start, $end): void {
                $query->whereBetween('start_date', [$start->toDateString(), $end->toDateString()])
                    ->orWhereBetween('due_date', [$start->toDateString(), $end->toDateString()]);
            })
            ->get()
            ->flatMap(function (Project $project) use ($start, $end) {
                $events = collect();

                if ($project->start_date && $project->start_date->betweenIncluded($start, $end)) {
                    $events->push($this->event('project_start', 'Project start', $project->name, $project->start_date->toDateString(), route('projects.show', $project, false)));
                }

                if ($project->due_date && $project->due_date->betweenIncluded($start, $end)) {
                    $events->push($this->event('project_due', 'Project due', $project->name, $project->due_date->toDateString(), route('projects.show', $project, false)));
                }

                return $events;
            });

        return Inertia::render('Calendar/Index', [
            'month' => $start->format('Y-m'),
            'monthLabel' => $start->format('F Y'),
            'previousMonth' => $start->subMonth()->format('Y-m'),
            'nextMonth' => $start->addMonth()->format('Y-m'),
            'todayMonth' => now()->format('Y-m'),
            'events' => $taskEvents->merge($projectEvents)->values(),
        ]);
    }

    private function event(string $type, string $label, string $title, string $date, string $url): array
    {
        return compact('type', 'label', 'title', 'date', 'url');
    }
}
