<?php

namespace App\Services\Spiritual;

use App\Models\BibleReadingPlan;
use App\Models\BibleReadingPlanDay;
use App\Models\BibleReadingProgress;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SpiritualReadingSummaryService
{
    public function forUser(User $user, ?Carbon $date = null): array
    {
        $date ??= now();
        $plan = BibleReadingPlan::query()
            ->with(['days.chapters' => fn ($query) => $query->orderBy('position')])
            ->where('is_default', true)
            ->whereNull('user_id')
            ->whereNull('workspace_id')
            ->first();

        if (! $plan) {
            return $this->emptySummary($date);
        }

        $chapterIds = $plan->days->flatMap->chapters->pluck('id');
        $readIds = BibleReadingProgress::query()
            ->where('user_id', $user->id)
            ->whereIn('bible_reading_plan_day_chapter_id', $chapterIds)
            ->whereNotNull('read_at')
            ->pluck('bible_reading_plan_day_chapter_id')
            ->all();
        $readMap = array_fill_keys($readIds, true);
        $days = $plan->days->map(fn (BibleReadingPlanDay $day) => $this->dayResource($day, $readMap));
        $today = $days->first(fn (array $day) => $day['reading_date'] === $date->toDateString())
            ?? $days->first(fn (array $day) => $day['status'] !== 'completed')
            ?? $days->last();
        $yesterday = $days->first(fn (array $day) => $day['reading_date'] === $date->copy()->subDay()->toDateString());
        $totalChapters = (int) $days->sum('total_chapters');
        $completedChapters = (int) $days->sum('completed_chapters');
        $behindCount = $days
            ->filter(fn (array $day) => $day['reading_date'] && $day['reading_date'] < $date->toDateString() && $day['status'] !== 'completed')
            ->count();
        $aheadCount = $days
            ->filter(fn (array $day) => $day['reading_date'] && $day['reading_date'] > $date->toDateString() && $day['completed_chapters'] > 0)
            ->count();
        $missedYesterday = $yesterday && $yesterday['status'] !== 'completed';

        return [
            'has_plan' => true,
            'today_label' => $this->readingLabel($today['chapters'] ?? collect()),
            'today_completed_chapters' => $today['completed_chapters'] ?? 0,
            'today_total_chapters' => $today['total_chapters'] ?? 0,
            'completed_chapters' => $completedChapters,
            'total_chapters' => $totalChapters,
            'remaining_chapters' => max(0, $totalChapters - $completedChapters),
            'current_streak' => $this->currentStreak($days, $date),
            'longest_streak' => $this->longestStreak($days),
            'behind_count' => $behindCount,
            'ahead_count' => $aheadCount,
            'status_label' => $this->statusLabel($behindCount, $aheadCount),
            'missed_yesterday' => $missedYesterday,
            'missed_yesterday_label' => $missedYesterday ? $this->readingLabel($yesterday['chapters']) : null,
            'suggested_action' => $missedYesterday ? 'Read missed portion first, then continue today if time allows.' : null,
            'continue_url' => route('spiritual.index'),
        ];
    }

    public function slackBlock(array $summary): array
    {
        $lines = [
            'Spiritual Reading',
            'Today: '.$summary['today_label'],
            "Progress: {$summary['completed_chapters']} / {$summary['total_chapters']} chapters",
            "Streak: {$summary['current_streak']} days",
            'Status: '.$summary['status_label'],
            'Encouragement: One chapter at a time. Keep going.',
        ];

        if ($summary['missed_yesterday']) {
            $lines[] = 'Yesterday missed: '.$summary['missed_yesterday_label'];
            $lines[] = 'Suggested action: '.$summary['suggested_action'];
        }

        return $lines;
    }

    private function dayResource(BibleReadingPlanDay $day, array $readMap): array
    {
        $chapters = $day->chapters->map(fn ($chapter) => [
            'id' => $chapter->id,
            'book_name' => $chapter->book_name,
            'chapter_number' => $chapter->chapter_number,
            'is_read' => isset($readMap[$chapter->id]),
        ]);
        $completed = $chapters->where('is_read', true)->count();
        $total = $chapters->count();
        $readingDate = $day->reading_date?->toDateString();

        return [
            'day_number' => $day->day_number,
            'reading_date' => $readingDate,
            'chapters' => $chapters,
            'completed_chapters' => $completed,
            'total_chapters' => $total,
            'status' => $total > 0 && $completed >= $total ? 'completed' : ($completed > 0 ? 'partial' : 'upcoming'),
        ];
    }

    private function readingLabel(Collection $chapters): string
    {
        if ($chapters->isEmpty()) {
            return 'No reading assigned';
        }

        return $chapters
            ->groupBy('book_name')
            ->map(function (Collection $bookChapters, string $book): string {
                $numbers = $bookChapters->pluck('chapter_number')->values();
                $first = $numbers->first();
                $last = $numbers->last();

                return $first === $last ? "{$book} {$first}" : "{$book} {$first}-{$last}";
            })
            ->values()
            ->implode(', ');
    }

    private function currentStreak(Collection $days, Carbon $date): int
    {
        $streak = 0;

        foreach ($days->sortByDesc('day_number') as $day) {
            if ($day['reading_date'] && $day['reading_date'] > $date->toDateString()) {
                continue;
            }

            if ($day['status'] !== 'completed') {
                return $streak;
            }

            $streak++;
        }

        return $streak;
    }

    private function longestStreak(Collection $days): int
    {
        $longest = 0;
        $current = 0;

        foreach ($days as $day) {
            if ($day['status'] === 'completed') {
                $current++;
                $longest = max($longest, $current);
            } else {
                $current = 0;
            }
        }

        return $longest;
    }

    private function statusLabel(int $behindCount, int $aheadCount): string
    {
        if ($behindCount > 0) {
            return "{$behindCount} ".str('day')->plural($behindCount).' behind';
        }

        if ($aheadCount > 0) {
            return "{$aheadCount} ".str('day')->plural($aheadCount).' ahead';
        }

        return 'On track';
    }

    private function emptySummary(Carbon $date): array
    {
        return [
            'has_plan' => false,
            'today_label' => 'No reading plan found',
            'today_completed_chapters' => 0,
            'today_total_chapters' => 0,
            'completed_chapters' => 0,
            'total_chapters' => 0,
            'remaining_chapters' => 0,
            'current_streak' => 0,
            'longest_streak' => 0,
            'behind_count' => 0,
            'ahead_count' => 0,
            'status_label' => 'No plan',
            'missed_yesterday' => false,
            'missed_yesterday_label' => null,
            'suggested_action' => null,
            'continue_url' => route('spiritual.index'),
        ];
    }
}
