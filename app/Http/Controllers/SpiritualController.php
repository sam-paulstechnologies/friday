<?php

namespace App\Http\Controllers;

use App\Models\BibleReadingPlan;
use App\Models\BibleReadingPlanDay;
use App\Models\BibleReadingPlanDayChapter;
use App\Models\BibleReadingProgress;
use App\Models\BibleTranslation;
use App\Models\BibleVerse;
use App\Models\SpiritualJournal;
use App\Models\SpiritualNote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SpiritualController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $workspaceId = $this->workspaceId($request);
        $plan = BibleReadingPlan::query()
            ->with(['days.chapters' => fn ($query) => $query->orderBy('position')])
            ->where('is_default', true)
            ->whereNull('user_id')
            ->whereNull('workspace_id')
            ->first();

        $progressIds = $plan
            ? BibleReadingProgress::query()
                ->where('user_id', $user->id)
                ->whereIn('bible_reading_plan_day_chapter_id', $plan->days->flatMap->chapters->pluck('id'))
                ->whereNotNull('read_at')
                ->pluck('bible_reading_plan_day_chapter_id')
                ->all()
            : [];

        $progressMap = array_fill_keys($progressIds, true);
        $days = $plan ? $plan->days->map(fn (BibleReadingPlanDay $day) => $this->dayResource($day, $progressMap))->values() : collect();
        $today = $this->todayDay($days);
        $translations = BibleTranslation::query()
            ->withCount('verses')
            ->orderByDesc('is_enabled')
            ->orderBy('name')
            ->get()
            ->map(fn (BibleTranslation $translation) => [
                'id' => $translation->id,
                'code' => $translation->code,
                'name' => $translation->name,
                'language' => $translation->language,
                'license' => $translation->license,
                'attribution' => $translation->attribution,
                'is_enabled' => $translation->is_enabled,
                'verses_count' => $translation->verses_count,
            ]);
        $selectedTranslation = $this->selectedTranslation($request, $translations);
        $nextUnread = $days
            ->flatMap(fn ($day) => collect($day['chapters'])->map(fn ($chapter) => [...$chapter, 'day_number' => $day['day_number']]))
            ->first(fn ($chapter) => ! $chapter['is_read']);

        return Inertia::render('Spiritual/Index', [
            'plan' => $plan ? [
                'id' => $plan->id,
                'name' => $plan->name,
                'plan_type' => $plan->plan_type,
                'duration_days' => $plan->duration_days,
                'starts_on' => $plan->starts_on?->toDateString(),
            ] : null,
            'summary' => $this->summary($days),
            'today' => $today,
            'todayScripture' => $this->scriptureForDay($today, $selectedTranslation?->id),
            'translations' => $translations,
            'selectedTranslationCode' => $selectedTranslation?->code,
            'days' => $days,
            'nextUnread' => $nextUnread,
            'journals' => SpiritualJournal::query()
                ->where('user_id', $user->id)
                ->latest('entry_date')
                ->limit(8)
                ->get(['id', 'entry_date', 'title', 'content', 'bible_reading_plan_day_id']),
            'notes' => SpiritualNote::query()
                ->where('user_id', $user->id)
                ->latest()
                ->limit(8)
                ->get(['id', 'title', 'content', 'book_name', 'chapter_number', 'tags']),
            'workspace_id' => $workspaceId,
        ]);
    }

    public function toggleReading(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'chapter_id' => ['required', 'integer', Rule::exists('bible_reading_plan_day_chapters', 'id')],
        ]);

        $progress = BibleReadingProgress::query()->firstOrNew([
            'user_id' => $request->user()->id,
            'bible_reading_plan_day_chapter_id' => $data['chapter_id'],
        ]);

        $progress->workspace_id = $this->workspaceId($request);
        $progress->read_at = $progress->exists && $progress->read_at ? null : now();
        $progress->save();

        return back()->with('success', 'Reading progress updated.');
    }

    public function storeJournal(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'bible_reading_plan_day_id' => ['nullable', 'integer', Rule::exists('bible_reading_plan_days', 'id')],
        ]);

        SpiritualJournal::create([
            ...$data,
            'user_id' => $request->user()->id,
            'workspace_id' => $this->workspaceId($request),
        ]);

        return back()->with('success', 'Journal saved.');
    }

    public function storeNote(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'book_name' => ['nullable', 'string', 'max:255'],
            'chapter_number' => ['nullable', 'integer', 'min:1'],
            'bible_reading_plan_day_id' => ['nullable', 'integer', Rule::exists('bible_reading_plan_days', 'id')],
            'tags' => ['nullable', 'string', 'max:500'],
        ]);

        SpiritualNote::create([
            ...$data,
            'tags' => $this->tags($data['tags'] ?? null),
            'user_id' => $request->user()->id,
            'workspace_id' => $this->workspaceId($request),
        ]);

        return back()->with('success', 'Spiritual note saved.');
    }

    private function dayResource(BibleReadingPlanDay $day, array $progressMap): array
    {
        $chapters = $day->chapters->map(fn (BibleReadingPlanDayChapter $chapter) => [
            'id' => $chapter->id,
            'book_name' => $chapter->book_name,
            'chapter_number' => $chapter->chapter_number,
            'is_read' => isset($progressMap[$chapter->id]),
        ]);
        $completed = $chapters->where('is_read', true)->count();
        $total = $chapters->count();
        $date = $day->reading_date?->toDateString();

        return [
            'id' => $day->id,
            'day_number' => $day->day_number,
            'reading_date' => $date,
            'chapters' => $chapters->values(),
            'completed_chapters' => $completed,
            'total_chapters' => $total,
            'status' => $this->status($completed, $total, $date),
        ];
    }

    private function selectedTranslation(Request $request, $translations): ?BibleTranslation
    {
        $requested = strtoupper((string) $request->query('translation', 'KJV'));
        $translationId = $translations->firstWhere('code', $requested)['id']
            ?? $translations->first(fn ($translation) => $translation['is_enabled'] && $translation['verses_count'] > 0)['id']
            ?? null;

        return $translationId ? BibleTranslation::query()->find($translationId) : null;
    }

    private function scriptureForDay(?array $day, ?int $translationId): array
    {
        if (! $day || ! $translationId) {
            return [];
        }

        return collect($day['chapters'])->map(function (array $chapter) use ($translationId): array {
            $verses = BibleVerse::query()
                ->with('book:id,name')
                ->where('bible_translation_id', $translationId)
                ->whereHas('book', fn ($query) => $query->where('name', $chapter['book_name']))
                ->where('chapter_number', $chapter['chapter_number'])
                ->orderBy('verse_number')
                ->get(['id', 'bible_book_id', 'chapter_number', 'verse_number', 'text'])
                ->map(fn (BibleVerse $verse) => [
                    'id' => $verse->id,
                    'verse_number' => $verse->verse_number,
                    'text' => $verse->text,
                ]);

            return [
                'chapter_id' => $chapter['id'],
                'book_name' => $chapter['book_name'],
                'chapter_number' => $chapter['chapter_number'],
                'verses' => $verses,
            ];
        })->all();
    }

    private function summary($days): array
    {
        $total = (int) $days->sum('total_chapters');
        $completed = (int) $days->sum('completed_chapters');
        $today = now()->toDateString();
        $currentDay = $days->first(fn ($day) => $day['reading_date'] === $today) ?? $days->first(fn ($day) => $day['status'] !== 'completed') ?? $days->last();
        $behind = $days->filter(fn ($day) => $day['reading_date'] && $day['reading_date'] < $today && $day['status'] !== 'completed')->count();
        $ahead = $days->filter(fn ($day) => $day['reading_date'] && $day['reading_date'] > $today && $day['completed_chapters'] > 0)->count();

        return [
            'total_chapters' => $total,
            'chapters_completed' => $completed,
            'chapters_remaining' => max(0, $total - $completed),
            'current_day' => $currentDay['day_number'] ?? 1,
            'days_left' => $days->whereIn('status', ['upcoming', 'partial', 'missed'])->count(),
            'percentage_complete' => $total > 0 ? (int) round(($completed / $total) * 100) : 0,
            'behind_count' => $behind,
            'ahead_count' => $ahead,
            'current_streak' => $this->currentStreak($days),
            'longest_streak' => $this->longestStreak($days),
        ];
    }

    private function todayDay($days): ?array
    {
        return $days->first(fn ($day) => $day['reading_date'] === now()->toDateString())
            ?? $days->first(fn ($day) => $day['status'] !== 'completed')
            ?? $days->last();
    }

    private function status(int $completed, int $total, ?string $date): string
    {
        if ($total > 0 && $completed >= $total) {
            return 'completed';
        }

        if ($completed > 0) {
            return 'partial';
        }

        return $date && Carbon::parse($date)->isPast() && $date !== now()->toDateString() ? 'missed' : 'upcoming';
    }

    private function currentStreak($days): int
    {
        $streak = 0;

        foreach ($days->sortByDesc('day_number') as $day) {
            if ($day['reading_date'] && $day['reading_date'] > now()->toDateString()) {
                continue;
            }

            if ($day['status'] !== 'completed') {
                return $streak;
            }

            $streak++;
        }

        return $streak;
    }

    private function longestStreak($days): int
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

    private function tags(?string $tags): ?array
    {
        if (! $tags) {
            return null;
        }

        return collect(explode(',', $tags))->map(fn ($tag) => trim($tag))->filter()->values()->all();
    }

    private function workspaceId(Request $request): ?int
    {
        return $request->user()->workspaces()->value('workspaces.id');
    }
}
