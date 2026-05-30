<?php

namespace App\Services\DailyReview;

use App\Models\DailyReview;
use App\Models\DailyReviewItem;
use App\Models\Task;
use App\Services\Spiritual\SpiritualReadingSummaryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DailyBriefingService
{
    private const SECTION_CAPS = [
        'focus' => 3,
        'overdue' => 5,
        'due_today' => 5,
        'scheduled_today' => 5,
        'upcoming' => 5,
    ];

    public function __construct(private readonly SpiritualReadingSummaryService $spiritualReadingSummaryService)
    {
    }

    public function build(DailyReview $review, array $options = []): array
    {
        $review->loadMissing(['user', 'items.task.project', 'items.task.portfolio']);

        $portfolio = strtolower((string) ($options['portfolio'] ?? 'all'));
        $priority = (string) ($options['priority'] ?? 'urgent-high');
        $limit = max(1, (int) ($options['limit'] ?? 20));
        $date = $review->review_date->toDateString();

        $summary = $this->summary($review, $portfolio, $priority);

        return [
            'title' => 'Friday Daily Briefing',
            'date' => $date,
            'portfolio_label' => 'Portfolio = '.$this->portfolioLabel($portfolio),
            'priority_label' => $priority === 'urgent-high' ? 'Showing urgent/high only' : 'Showing all priorities',
            'summary' => $summary,
            'summary_line' => "Open tasks: {$summary['open']} | Overdue: {$summary['overdue']} | Due today: {$summary['due_today']} | Due this week: {$summary['due_week']}",
            'today_focus_line' => $this->todayFocusLine($review),
            'missed_yesterday_count' => $this->missedYesterdayCount($review),
            'spiritual' => $this->spiritualReadingSummaryService->forUser($review->user, $review->review_date),
            'portfolio_summary' => $this->portfolioSummary($review, $priority),
            'sections' => $this->sections($review->items->sortBy('position')->values(), $limit),
        ];
    }

    public function caption(array $briefing): string
    {
        $summary = $briefing['summary'];

        return "{$briefing['title']} - {$briefing['date']}\n"
            ."Open: {$summary['open']} | Overdue: {$summary['overdue']} | Due today: {$summary['due_today']} | Due this week: {$summary['due_week']}\n"
            .'Focus: '.$briefing['today_focus_line']."\n"
            .'Missed yesterday: '.$briefing['missed_yesterday_count']."\n"
            .'Spiritual: '.$briefing['spiritual']['today_label'].' | '.$briefing['spiritual']['status_label'];
    }

    public function textMessage(array $briefing): string
    {
        $lines = [
            "{$briefing['title']} - {$briefing['date']}",
            $briefing['summary_line'],
            'Today focus: '.$briefing['today_focus_line'],
            'Missed yesterday: '.$briefing['missed_yesterday_count'],
            '',
            ...$this->spiritualReadingSummaryService->slackBlock($briefing['spiritual']),
            '',
            '```',
            ...$this->taskTable($briefing['sections']),
            '```',
            '',
            'Reply with commands like `done 1`, `move 2 tomorrow`, `note 3 waiting on feedback`, or `help`.',
        ];

        return implode("\n", $lines);
    }

    private function summary(DailyReview $review, string $portfolio, string $priority): array
    {
        $base = $this->taskQuery($review, $portfolio, $priority);
        $today = $review->review_date->toDateString();
        $nextWeek = $review->review_date->copy()->addDays(7)->toDateString();

        return [
            'open' => (clone $base)->count(),
            'overdue' => (clone $base)->whereDate('due_date', '<', $today)->count(),
            'due_today' => (clone $base)->whereDate('due_date', $today)->count(),
            'due_week' => (clone $base)->whereBetween('due_date', [$today, $nextWeek])->count(),
        ];
    }

    private function portfolioSummary(DailyReview $review, string $priority): array
    {
        return collect(['SayaraForce', 'ChurchForce'])
            ->map(function (string $portfolio) use ($review, $priority): array {
                $base = $this->taskQuery($review, strtolower($portfolio), $priority);
                $today = $review->review_date->toDateString();

                return [
                    'portfolio' => $portfolio,
                    'open' => (clone $base)->count(),
                    'overdue' => (clone $base)->whereDate('due_date', '<', $today)->count(),
                    'due_today' => (clone $base)->whereDate('due_date', $today)->count(),
                    'urgent_high' => (clone $base)->whereIn('priority', ['urgent', 'high'])->count(),
                ];
            })
            ->all();
    }

    private function sections(Collection $items, int $limit): array
    {
        $seenTaskIds = [];
        $remaining = $limit;
        $sections = [];

        foreach (self::SECTION_CAPS as $section => $cap) {
            $rows = [];

            if ($remaining > 0) {
                foreach ($items->where('item_type', $section) as $item) {
                    if (count($rows) >= min($cap, $remaining)) {
                        break;
                    }

                    if (in_array($item->task_id, $seenTaskIds, true)) {
                        continue;
                    }

                    $seenTaskIds[] = $item->task_id;
                    $rows[] = $this->row($item);
                }
            }

            $remaining -= count($rows);
            $sections[$section] = $rows;
        }

        return $sections;
    }

    private function row(DailyReviewItem $item): array
    {
        return [
            'no' => (string) $item->position,
            'type' => str_replace('_', ' ', (string) $item->item_type),
            'priority' => (string) $item->snapshot_priority,
            'due' => $item->snapshot_due_date?->toDateString() ?? 'no due',
            'portfolio' => $item->task?->portfolio?->name ?? 'None',
            'project' => $item->task?->project?->name ?? 'No project',
            'task' => (string) $item->snapshot_title,
        ];
    }

    private function taskTable(array $sections): array
    {
        $rows = [
            $this->taskTableRow('No.', 'Type', 'Priority', 'Due', 'Portfolio', 'Project', 'Task'),
            $this->taskTableRow('---', '----', '--------', '---', '---------', '-------', '----'),
        ];

        foreach ($sections as $sectionRows) {
            foreach ($sectionRows as $row) {
                $rows[] = $this->taskTableRow($row['no'], $row['type'], $row['priority'], $row['due'], $row['portfolio'], $row['project'], $row['task']);
            }
        }

        return $rows;
    }

    private function todayFocusLine(DailyReview $review): string
    {
        $focus = $review->items
            ->where('item_type', 'focus')
            ->take(3)
            ->pluck('snapshot_title')
            ->filter()
            ->values();

        return $focus->isEmpty() ? 'No focus tasks selected' : $focus->implode(' | ');
    }

    private function missedYesterdayCount(DailyReview $review): int
    {
        return Task::query()
            ->where(function (Builder $query) use ($review): void {
                $query->where('assignee_id', $review->user_id)
                    ->orWhere('reporter_id', $review->user_id);
            })
            ->whereNotIn('status', DailyReviewService::CLOSED_STATUSES)
            ->whereDate('due_date', $review->review_date->copy()->subDay()->toDateString())
            ->count();
    }

    private function taskTableRow(string $number, string $type, string $priority, string $due, string $portfolio, string $project, string $task): string
    {
        return sprintf(
            '%-3s %-10s %-9s %-12s %-16s %-24s %-30s',
            $this->fixedWidth($number, 3),
            $this->fixedWidth($type, 10),
            $this->fixedWidth($priority, 9),
            $this->fixedWidth($due, 12),
            $this->fixedWidth($portfolio, 16),
            $this->fixedWidth($project, 24),
            $this->fixedWidth($task, 30),
        );
    }

    private function fixedWidth(string $value, int $width): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';

        return strlen($value) > $width ? substr($value, 0, $width - 3).'...' : $value;
    }

    private function taskQuery(DailyReview $review, string $portfolio, string $priority): Builder
    {
        return Task::query()
            ->where(function (Builder $query) use ($review): void {
                $query->where('assignee_id', $review->user_id)
                    ->orWhere('reporter_id', $review->user_id);
            })
            ->whereNotIn('status', DailyReviewService::CLOSED_STATUSES)
            ->when($portfolio !== '' && $portfolio !== 'all', function (Builder $query) use ($portfolio): void {
                $query->whereHas('portfolio', fn (Builder $portfolioQuery) => $portfolioQuery->whereRaw('lower(name) = ?', [$portfolio]));
            })
            ->when($priority === 'urgent-high', fn (Builder $query) => $query->whereIn('priority', ['urgent', 'high']));
    }

    private function portfolioLabel(string $portfolio): string
    {
        return match ($portfolio) {
            'sayaraforce' => 'SayaraForce',
            'churchforce' => 'ChurchForce',
            default => 'All',
        };
    }
}
