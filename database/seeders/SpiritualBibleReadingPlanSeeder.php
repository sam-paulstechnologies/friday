<?php

namespace Database\Seeders;

use App\Models\BibleReadingPlan;
use App\Models\BibleReadingPlanDay;
use App\Services\Bible\BibleCanon;
use Illuminate\Database\Seeder;

class SpiritualBibleReadingPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plan = BibleReadingPlan::query()->firstOrCreate(
            ['user_id' => null, 'workspace_id' => null, 'slug' => 'canonical-bible-in-90-days'],
            [
                'name' => 'Canonical Bible in 90 Days',
                'plan_type' => 'canonical',
                'duration_days' => 90,
                'starts_on' => now()->toDateString(),
                'is_default' => true,
            ],
        );

        $chapters = collect(BibleCanon::books())->flatMap(function (array $book, int $index): array {
            return collect(range(1, $book['chapters']))->map(fn (int $chapter): array => [
                'book_name' => $book['name'],
                'book_order' => $index + 1,
                'chapter_number' => $chapter,
            ])->all();
        })->values();

        $cursor = 0;

        for ($dayNumber = 1; $dayNumber <= 90; $dayNumber++) {
            $chapterCount = $dayNumber <= 19 ? 14 : 13;
            $day = BibleReadingPlanDay::query()->firstOrCreate(
                ['bible_reading_plan_id' => $plan->id, 'day_number' => $dayNumber],
                ['reading_date' => now()->addDays($dayNumber - 1)->toDateString()],
            );

            $position = 1;
            foreach ($chapters->slice($cursor, $chapterCount) as $chapter) {
                $day->chapters()->firstOrCreate(
                    [
                        'book_name' => $chapter['book_name'],
                        'chapter_number' => $chapter['chapter_number'],
                    ],
                    [
                        'book_order' => $chapter['book_order'],
                        'position' => $position,
                    ],
                );
                $position++;
            }

            $cursor += $chapterCount;
        }
    }

}
