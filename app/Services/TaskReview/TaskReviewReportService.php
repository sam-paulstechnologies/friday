<?php

namespace App\Services\TaskReview;

use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class TaskReviewReportService
{
    private const INTENT_BUCKETS = [
        'Work / Career',
        'SayaraForce',
        'ChurchForce',
        "Paul's Photography",
        'UAE Realtor App',
        'Career Growth',
        'Personal / Foundation',
        'Helping Others',
    ];

    public function build(): array
    {
        $tasks = Task::query()
            ->with([
                'area:id,name',
                'portfolio:id,name,area_id',
                'project:id,name,portfolio_id',
                'assignee:id,name,email',
                'reporter:id,name,email',
            ])
            ->orderByRaw('area_id is null')
            ->orderBy('area_id')
            ->orderByRaw('portfolio_id is null')
            ->orderBy('portfolio_id')
            ->orderByRaw('project_id is null')
            ->orderBy('project_id')
            ->orderByRaw("case priority when 'urgent' then 1 when 'high' then 2 when 'medium' then 3 when 'low' then 4 else 5 end")
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        $rows = $tasks->map(fn (Task $task): array => $this->taskRow($task))->values();

        return [
            'generated_at' => now()->toDateTimeString(),
            'summary' => $this->executiveSummary($rows),
            'areaSummary' => $this->groupSummary($rows, 'area'),
            'portfolioSummary' => $this->portfolioSummary($rows),
            'projectSummary' => $this->projectSummary($rows),
            'grouped' => [
                'area' => $this->simpleGroup($rows, 'area'),
                'portfolio' => $this->simpleGroup($rows, 'portfolio'),
                'project' => $this->simpleGroup($rows, 'project'),
                'status' => $this->simpleGroup($rows, 'status'),
                'priority' => $this->simpleGroup($rows, 'priority'),
                'due_date_bucket' => $this->simpleGroup($rows, 'due_date_bucket'),
                'assignee' => $this->simpleGroup($rows, 'assignee'),
            ],
            'tasks' => $rows->all(),
            'priorityReviewCandidates' => $this->priorityReviewCandidates($rows),
            'intentBuckets' => $this->intentBuckets($rows),
            'reviewQuestions' => [
                'Which tasks should be done this week?',
                'Which tasks can be postponed?',
                'Which tasks should be converted into projects?',
                'Which tasks need owner/due date?',
                'Which urgent tasks are truly urgent?',
            ],
        ];
    }

    public function export(): array
    {
        $report = $this->build();

        $markdownPath = storage_path('app/reviews/task-review.md');
        $csvPath = storage_path('app/reviews/task-review.csv');

        File::ensureDirectoryExists(dirname($markdownPath));

        file_put_contents($markdownPath, $this->markdown($report));
        $this->writeCsv($csvPath, $report['tasks']);

        return [
            'markdown' => $markdownPath,
            'csv' => $csvPath,
            'report' => $report,
        ];
    }

    private function taskRow(Task $task): array
    {
        $dueDate = $task->due_date?->toDateString();

        return [
            'id' => $task->id,
            'area' => $task->area?->name ?? 'No area',
            'portfolio' => $task->portfolio?->name ?? 'No portfolio',
            'project' => $task->project?->name ?? 'No project',
            'title' => $task->title,
            'status' => $task->status,
            'priority' => $task->priority,
            'due_date' => $dueDate,
            'due_date_bucket' => $this->dueDateBucket($task),
            'assignee' => $task->assignee?->name ?? $task->assignee?->email ?? 'Unassigned',
            'assignee_id' => $task->assignee_id,
            'reporter' => $task->reporter?->name ?? $task->reporter?->email ?? 'No reporter',
            'summary' => $this->shortSummary($task->description),
            'is_open' => $this->isOpen($task->status),
            'is_completed' => $task->status === 'completed',
            'is_overdue' => $this->isOpen($task->status) && $dueDate !== null && $dueDate < now()->toDateString(),
            'is_due_today' => $this->isOpen($task->status) && $dueDate === now()->toDateString(),
            'is_due_this_week' => $this->isOpen($task->status) && $dueDate !== null && $dueDate >= now()->toDateString() && $dueDate <= now()->addDays(7)->toDateString(),
        ];
    }

    private function executiveSummary(Collection $rows): array
    {
        return [
            'total_tasks' => $rows->count(),
            'open_tasks' => $rows->where('is_open', true)->count(),
            'completed_tasks' => $rows->where('is_completed', true)->count(),
            'overdue_tasks' => $rows->where('is_overdue', true)->count(),
            'due_today' => $rows->where('is_due_today', true)->count(),
            'due_this_week' => $rows->where('is_due_this_week', true)->count(),
            'no_due_date' => $rows->where('is_open', true)->whereNull('due_date')->count(),
            'urgent_high_open_tasks' => $rows->where('is_open', true)->whereIn('priority', ['urgent', 'high'])->count(),
            'unassigned_tasks' => $rows->where('is_open', true)->whereNull('assignee_id')->count(),
        ];
    }

    private function groupSummary(Collection $rows, string $field): array
    {
        return $rows
            ->groupBy($field)
            ->map(fn (Collection $tasks, string $name): array => [
                'name' => $name,
                'total_tasks' => $tasks->count(),
                'open' => $tasks->where('is_open', true)->count(),
                'completed' => $tasks->where('is_completed', true)->count(),
                'overdue' => $tasks->where('is_overdue', true)->count(),
                'urgent_high' => $tasks->where('is_open', true)->whereIn('priority', ['urgent', 'high'])->count(),
                'no_due_date' => $tasks->where('is_open', true)->whereNull('due_date')->count(),
            ])
            ->sortByDesc('total_tasks')
            ->values()
            ->all();
    }

    private function portfolioSummary(Collection $rows): array
    {
        return $rows
            ->groupBy('portfolio')
            ->map(fn (Collection $tasks, string $portfolio): array => [
                'portfolio' => $portfolio,
                'area' => $tasks->first()['area'],
                'total_tasks' => $tasks->count(),
                'open' => $tasks->where('is_open', true)->count(),
                'completed' => $tasks->where('is_completed', true)->count(),
                'overdue' => $tasks->where('is_overdue', true)->count(),
                'urgent' => $tasks->where('priority', 'urgent')->count(),
                'high' => $tasks->where('priority', 'high')->count(),
                'medium' => $tasks->where('priority', 'medium')->count(),
                'low' => $tasks->where('priority', 'low')->count(),
                'no_due_date' => $tasks->where('is_open', true)->whereNull('due_date')->count(),
            ])
            ->sortBy([['area', 'asc'], ['portfolio', 'asc']])
            ->values()
            ->all();
    }

    private function projectSummary(Collection $rows): array
    {
        return $rows
            ->groupBy('project')
            ->map(fn (Collection $tasks, string $project): array => [
                'project' => $project,
                'portfolio' => $tasks->first()['portfolio'],
                'total_tasks' => $tasks->count(),
                'open' => $tasks->where('is_open', true)->count(),
                'completed' => $tasks->where('is_completed', true)->count(),
                'overdue' => $tasks->where('is_overdue', true)->count(),
                'due_this_week' => $tasks->where('is_due_this_week', true)->count(),
                'urgent_high' => $tasks->where('is_open', true)->whereIn('priority', ['urgent', 'high'])->count(),
            ])
            ->sortBy([['portfolio', 'asc'], ['project', 'asc']])
            ->values()
            ->all();
    }

    private function simpleGroup(Collection $rows, string $field): array
    {
        return $rows
            ->groupBy($field)
            ->map(fn (Collection $tasks, string $name): array => ['name' => $name, 'count' => $tasks->count()])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    private function priorityReviewCandidates(Collection $rows): array
    {
        return [
            'possible_urgent_not_urgent' => $rows
                ->filter(fn (array $task): bool => $task['priority'] === 'urgent'
                    && $task['due_date'] === null
                    && ! Str::of($task['title'].' '.$task['summary'])->lower()->contains(['launch', 'blocker', 'deployment', 'critical']))
                ->values()
                ->all(),
            'possible_high_priority' => $rows
                ->filter(fn (array $task): bool => $task['is_due_this_week']
                    && in_array($task['priority'], ['medium', 'low'], true)
                    && $task['is_open'])
                ->values()
                ->all(),
            'possible_overdue_cleanup' => $rows
                ->filter(fn (array $task): bool => $task['is_overdue'] && in_array($task['priority'], ['medium', 'low'], true))
                ->values()
                ->all(),
            'unassigned_active_tasks' => $rows
                ->filter(fn (array $task): bool => $task['is_open'] && $task['assignee_id'] === null)
                ->values()
                ->all(),
            'no_due_date_active_tasks' => $rows
                ->filter(fn (array $task): bool => $task['is_open'] && $task['due_date'] === null)
                ->values()
                ->all(),
            'completed_with_active_signals' => $rows
                ->filter(fn (array $task): bool => $task['status'] === 'completed' && in_array($task['priority'], ['urgent', 'high'], true))
                ->values()
                ->all(),
        ];
    }

    private function intentBuckets(Collection $rows): array
    {
        return collect(self::INTENT_BUCKETS)
            ->mapWithKeys(fn (string $bucket): array => [$bucket => []])
            ->merge($rows->groupBy(fn (array $task): string => $this->intentBucket($task))->map->values()->all())
            ->all();
    }

    private function intentBucket(array $task): string
    {
        $text = Str::of($task['area'].' '.$task['portfolio'].' '.$task['project'])->lower();

        return match (true) {
            $text->contains('sayara') => 'SayaraForce',
            $text->contains('church') => 'ChurchForce',
            $text->contains(['paul', 'photography']) => "Paul's Photography",
            $text->contains(['uae realtor', 'realtor']) => 'UAE Realtor App',
            $text->contains(['career growth', 'growth', 'learning', 'skill']) => 'Career Growth',
            $text->contains(['personal', 'foundation', 'life', 'health', 'home']) => 'Personal / Foundation',
            $text->contains(['helping others', 'help others', 'support', 'community']) => 'Helping Others',
            default => 'Work / Career',
        };
    }

    private function markdown(array $report): string
    {
        $lines = [
            '# Task Review Pack',
            '',
            'Generated: '.$report['generated_at'],
            '',
            '## A. Executive Summary',
            '',
            $this->markdownTable([
                ['Metric', 'Count'],
                ...collect($report['summary'])->map(fn ($value, string $key): array => [$this->label($key), $value])->all(),
            ]),
            '',
            '## Grouping Overview',
            '',
            $this->groupingOverview($report['grouped']),
            '',
            '## B. Area Summary',
            '',
            $this->markdownTable([['Area', 'Total', 'Open', 'Completed', 'Overdue', 'Urgent/High', 'No due date'], ...array_map(fn ($row) => [$row['name'], $row['total_tasks'], $row['open'], $row['completed'], $row['overdue'], $row['urgent_high'], $row['no_due_date']], $report['areaSummary'])]),
            '',
            '## C. Portfolio Summary',
            '',
            $this->markdownTable([['Portfolio', 'Area', 'Total', 'Open', 'Completed', 'Overdue', 'Urgent', 'High', 'Medium', 'Low', 'No due date'], ...array_map(fn ($row) => [$row['portfolio'], $row['area'], $row['total_tasks'], $row['open'], $row['completed'], $row['overdue'], $row['urgent'], $row['high'], $row['medium'], $row['low'], $row['no_due_date']], $report['portfolioSummary'])]),
            '',
            '## D. Project Summary',
            '',
            $this->markdownTable([['Project', 'Portfolio', 'Total', 'Open', 'Completed', 'Overdue', 'Due this week', 'Urgent/High'], ...array_map(fn ($row) => [$row['project'], $row['portfolio'], $row['total_tasks'], $row['open'], $row['completed'], $row['overdue'], $row['due_this_week'], $row['urgent_high']], $report['projectSummary'])]),
            '',
            '## E. Full Task List',
            '',
            $this->taskTable($report['tasks']),
            '',
            '## F. Priority Review Candidates',
            '',
            $this->candidateSections($report['priorityReviewCandidates']),
            '',
            '## G. My Intent-Based Buckets',
            '',
            $this->intentSections($report['intentBuckets']),
            '',
            '## H. Suggested Review Questions',
            '',
            ...array_map(fn (string $question): string => '- '.$question, $report['reviewQuestions']),
            '',
        ];

        return implode(PHP_EOL, $lines);
    }

    private function taskTable(array $tasks): string
    {
        return $this->markdownTable([
            ['ID', 'Area', 'Portfolio', 'Project', 'Title', 'Status', 'Priority', 'Due Date', 'Assignee', 'Reporter', 'Description/Notes short summary'],
            ...array_map(fn (array $task): array => [
                $task['id'],
                $task['area'],
                $task['portfolio'],
                $task['project'],
                $task['title'],
                $task['status'],
                $task['priority'],
                $task['due_date'] ?? '',
                $task['assignee'],
                $task['reporter'],
                $task['summary'],
            ], $tasks),
        ]);
    }

    private function groupingOverview(array $grouped): string
    {
        return collect($grouped)
            ->map(function (array $rows, string $group): string {
                return '### '.$this->label($group).PHP_EOL.PHP_EOL.$this->markdownTable([
                    [$this->label($group), 'Count'],
                    ...array_map(fn (array $row): array => [$row['name'], $row['count']], $rows),
                ]);
            })
            ->implode(PHP_EOL.PHP_EOL);
    }

    private function candidateSections(array $candidates): string
    {
        $labels = [
            'possible_urgent_not_urgent' => 'Possible urgent tasks that may not be urgent',
            'possible_high_priority' => 'Possible high-priority tasks',
            'possible_overdue_cleanup' => 'Possible overdue cleanup',
            'unassigned_active_tasks' => 'Unassigned active tasks',
            'no_due_date_active_tasks' => 'No due date active tasks',
            'completed_with_active_signals' => 'Completed but still showing active signals',
        ];

        return collect($labels)
            ->map(function (string $label, string $key) use ($candidates): string {
                $tasks = $candidates[$key] ?? [];

                return '### '.$label.PHP_EOL.PHP_EOL.($tasks === [] ? '_None found._' : $this->taskTable($tasks));
            })
            ->implode(PHP_EOL.PHP_EOL);
    }

    private function intentSections(array $buckets): string
    {
        return collect($buckets)
            ->map(function (mixed $tasks, string $bucket): string {
                $taskRows = collect($tasks)->values()->all();

                return '### '.$bucket.PHP_EOL.PHP_EOL.($taskRows === [] ? '_No tasks._' : $this->taskTable($taskRows));
            })
            ->implode(PHP_EOL.PHP_EOL);
    }

    private function writeCsv(string $path, array $tasks): void
    {
        $handle = fopen($path, 'w');

        fputcsv($handle, [
            'ID',
            'Area',
            'Portfolio',
            'Project',
            'Status',
            'Priority',
            'Due Date Bucket',
            'Due Date',
            'Assignee',
            'Reporter',
            'Title',
            'Description/Notes short summary',
        ]);

        foreach ($tasks as $task) {
            fputcsv($handle, [
                $task['id'],
                $task['area'],
                $task['portfolio'],
                $task['project'],
                $task['status'],
                $task['priority'],
                $task['due_date_bucket'],
                $task['due_date'],
                $task['assignee'],
                $task['reporter'],
                $task['title'],
                $task['summary'],
            ]);
        }

        fclose($handle);
    }

    private function markdownTable(array $rows): string
    {
        if ($rows === []) {
            return '_No data._';
        }

        $header = array_shift($rows);
        $lines = [
            '| '.implode(' | ', array_map(fn ($value): string => $this->cell($value), $header)).' |',
            '| '.implode(' | ', array_fill(0, count($header), '---')).' |',
        ];

        foreach ($rows as $row) {
            $lines[] = '| '.implode(' | ', array_map(fn ($value): string => $this->cell($value), $row)).' |';
        }

        return implode(PHP_EOL, $lines);
    }

    private function cell(mixed $value): string
    {
        return str_replace(["\r", "\n", '|'], [' ', ' ', '\|'], (string) $value);
    }

    private function dueDateBucket(Task $task): string
    {
        if ($task->due_date === null) {
            return 'No due date';
        }

        $due = $task->due_date->toDateString();
        $today = now()->toDateString();

        if ($this->isOpen($task->status) && $due < $today) {
            return 'Overdue';
        }

        if ($due === $today) {
            return 'Due today';
        }

        if ($due > $today && $due <= now()->addDays(7)->toDateString()) {
            return 'Due this week';
        }

        return $due > $today ? 'Future' : 'Past completed';
    }

    private function shortSummary(?string $description): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $description)));

        return $text === '' ? '' : Str::limit($text, 140);
    }

    private function isOpen(string $status): bool
    {
        return ! in_array($status, ['completed', 'done', 'archived'], true);
    }

    private function label(string $value): string
    {
        return Str::of($value)->replace('_', ' ')->title()->toString();
    }
}
