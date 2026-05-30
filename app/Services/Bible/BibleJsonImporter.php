<?php

namespace App\Services\Bible;

use App\Models\BibleBook;
use App\Models\BibleTranslation;
use App\Models\BibleVerse;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BibleJsonImporter
{
    public function import(string $path, array $translationData): int
    {
        if (! is_file($path)) {
            throw new RuntimeException("Bible JSON file not found: {$path}");
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload)) {
            throw new RuntimeException("Bible JSON file is invalid: {$path}");
        }

        $this->seedBooks();

        return DB::transaction(function () use ($payload, $translationData): int {
            $translation = BibleTranslation::query()->updateOrCreate(
                ['code' => $translationData['code']],
                $translationData,
            );

            $booksByOrder = BibleBook::query()->orderBy('book_order')->get()->values();
            $rows = [];
            $now = now();
            $count = 0;

            foreach ($payload as $bookIndex => $bookPayload) {
                $book = $booksByOrder[$bookIndex] ?? null;
                if (! $book || ! is_array($bookPayload)) {
                    continue;
                }

                foreach ($bookPayload as $chapterIndex => $chapterPayload) {
                    if (! is_array($chapterPayload)) {
                        continue;
                    }

                    foreach ($chapterPayload as $verseIndex => $text) {
                        $rows[] = [
                            'bible_translation_id' => $translation->id,
                            'bible_book_id' => $book->id,
                            'chapter_number' => $chapterIndex + 1,
                            'verse_number' => $verseIndex + 1,
                            'text' => (string) $text,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                        $count++;

                        if (count($rows) >= 1000) {
                            BibleVerse::query()->upsert($rows, [
                                'bible_translation_id',
                                'bible_book_id',
                                'chapter_number',
                                'verse_number',
                            ], ['text', 'updated_at']);
                            $rows = [];
                        }
                    }
                }
            }

            if ($rows !== []) {
                BibleVerse::query()->upsert($rows, [
                    'bible_translation_id',
                    'bible_book_id',
                    'chapter_number',
                    'verse_number',
                ], ['text', 'updated_at']);
            }

            return $count;
        });
    }

    public function seedBooks(): void
    {
        foreach (BibleCanon::bookRows() as $book) {
            BibleBook::query()->updateOrCreate(['slug' => $book['slug']], $book);
        }
    }
}
