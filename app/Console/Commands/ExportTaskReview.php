<?php

namespace App\Console\Commands;

use App\Services\TaskReview\TaskReviewReportService;
use Illuminate\Console\Command;

class ExportTaskReview extends Command
{
    protected $signature = 'taskflow:export-task-review';

    protected $description = 'Export a read-only task review pack for cleanup and prioritization.';

    public function handle(TaskReviewReportService $reportService): int
    {
        $export = $reportService->export();
        $summary = $export['report']['summary'];

        $this->info('Task review pack exported.');
        $this->line('Markdown: '.$export['markdown']);
        $this->line('CSV: '.$export['csv']);
        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total tasks', $summary['total_tasks']],
                ['Open tasks', $summary['open_tasks']],
                ['Completed tasks', $summary['completed_tasks']],
                ['Overdue tasks', $summary['overdue_tasks']],
                ['Due today', $summary['due_today']],
                ['Due this week', $summary['due_this_week']],
                ['No due date', $summary['no_due_date']],
                ['Urgent/high open tasks', $summary['urgent_high_open_tasks']],
                ['Unassigned tasks', $summary['unassigned_tasks']],
            ]
        );

        return self::SUCCESS;
    }
}
