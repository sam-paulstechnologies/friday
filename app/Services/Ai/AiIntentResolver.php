<?php

namespace App\Services\Ai;

use App\Models\Area;
use App\Models\Portfolio;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AiIntentResolver
{
    public function resolve(string $prompt): array
    {
        $text = Str::lower($prompt);
        $matches = collect();

        foreach ($this->aliases() as $needle => $targets) {
            if (! Str::of($text)->contains($needle)) {
                continue;
            }

            if (count($targets) > 1) {
                $grouped = collect($targets)
                    ->flatMap(fn (string $target): Collection => $this->findNamedMatches($target, 95))
                    ->unique(fn (array $match): string => $match['type'].':'.$match['id'])
                    ->values();

                if ($grouped->isNotEmpty()) {
                    return [
                        'status' => 'matched',
                        'match' => [
                            'type' => 'multi',
                            'id' => null,
                            'name' => $grouped->pluck('name')->implode(' + '),
                            'confidence' => 95,
                            'matches' => $grouped->all(),
                        ],
                        'matches' => $grouped->all(),
                    ];
                }
            }

            foreach ($targets as $target) {
                $matches = $matches->merge($this->findNamedMatches($target, 95));
            }
        }

        $matches = $matches
            ->merge($this->entityMatches(Area::query()->select(['id', 'name'])->get(), 'area', $text))
            ->merge($this->entityMatches(Portfolio::query()->select(['id', 'name'])->get(), 'portfolio', $text))
            ->merge($this->entityMatches(Project::query()->select(['id', 'name'])->get(), 'project', $text))
            ->merge($this->taskTextMatches($text))
            ->unique(fn (array $match): string => $match['type'].':'.$match['id'])
            ->sortByDesc('confidence')
            ->values();

        if ($matches->isEmpty()) {
            return ['status' => 'none', 'match' => null, 'matches' => []];
        }

        $top = $matches->first();
        $close = $matches->filter(fn (array $match): bool => $match['confidence'] >= max(60, $top['confidence'] - 12))->values();

        if ($top['confidence'] < 60 || ($close->count() > 1 && $top['confidence'] < 90)) {
            return ['status' => 'ambiguous', 'match' => null, 'matches' => $close->take(5)->all()];
        }

        return ['status' => 'matched', 'match' => $top, 'matches' => $matches->take(5)->all()];
    }

    private function aliases(): array
    {
        return [
            'career tasks' => ['Career'],
            'career related' => ['Career'],
            'stellantis work' => ['Stellantis GCC', 'Stellantis South Africa'],
            'stellantis priorities' => ['Stellantis GCC', 'Stellantis South Africa'],
            'photography' => ["Paul's Photography"],
            'paul' => ["Paul's Photography"],
            'realtor app' => ['UAE Realtor Agents App'],
            'eid sprint' => ['UAE Realtor Agents App'],
            'garage crm' => ['SayaraForce'],
            'sayaraforce' => ['SayaraForce'],
            'church app' => ['ChurchForce'],
            'churchforce' => ['ChurchForce'],
            'campaigns' => ["Paul's Photography"],
        ];
    }

    private function findNamedMatches(string $name, int $confidence): Collection
    {
        return collect([
            ...$this->namedEntity(Area::query()->select(['id', 'name'])->get(), 'area', $name, $confidence),
            ...$this->namedEntity(Portfolio::query()->select(['id', 'name'])->get(), 'portfolio', $name, $confidence),
            ...$this->namedEntity(Project::query()->select(['id', 'name'])->get(), 'project', $name, $confidence),
        ]);
    }

    private function namedEntity(Collection $entities, string $type, string $name, int $confidence): array
    {
        return $entities
            ->filter(fn ($entity): bool => Str::lower($entity->name) === Str::lower($name)
                || Str::of(Str::lower($entity->name))->contains(Str::lower($name))
                || Str::of(Str::lower($name))->contains(Str::lower($entity->name)))
            ->map(fn ($entity): array => [
                'type' => $type,
                'id' => $entity->id,
                'name' => $entity->name,
                'confidence' => $confidence,
            ])
            ->values()
            ->all();
    }

    private function entityMatches(Collection $entities, string $type, string $text): Collection
    {
        return $entities
            ->map(function ($entity) use ($type, $text): ?array {
                $name = Str::lower($entity->name);

                if (Str::of($text)->contains($name)) {
                    return ['type' => $type, 'id' => $entity->id, 'name' => $entity->name, 'confidence' => 90];
                }

                $words = collect(preg_split('/\s+/', $name))->filter(fn (string $word): bool => strlen($word) >= 4);
                $hits = $words->filter(fn (string $word): bool => Str::of($text)->contains($word))->count();

                if ($words->count() > 0 && $hits > 0) {
                    return ['type' => $type, 'id' => $entity->id, 'name' => $entity->name, 'confidence' => min(85, 45 + ($hits * 20))];
                }

                return null;
            })
            ->filter()
            ->values();
    }

    private function taskTextMatches(string $text): Collection
    {
        return Task::query()
            ->with(['area:id,name', 'portfolio:id,name', 'project:id,name'])
            ->select(['id', 'area_id', 'portfolio_id', 'project_id', 'title', 'description'])
            ->latest()
            ->limit(250)
            ->get()
            ->flatMap(function (Task $task) use ($text): array {
                $haystack = Str::lower($task->title.' '.$task->description);
                $terms = collect(preg_split('/[^a-z0-9]+/', $text))->filter(fn (string $term): bool => strlen($term) >= 5);
                $hits = $terms->filter(fn (string $term): bool => Str::of($haystack)->contains($term))->count();

                if ($hits === 0) {
                    return [];
                }

                $confidence = min(80, 40 + ($hits * 10));

                return collect([
                    $task->project ? ['type' => 'project', 'id' => $task->project->id, 'name' => $task->project->name, 'confidence' => $confidence] : null,
                    $task->portfolio ? ['type' => 'portfolio', 'id' => $task->portfolio->id, 'name' => $task->portfolio->name, 'confidence' => $confidence - 5] : null,
                    $task->area ? ['type' => 'area', 'id' => $task->area->id, 'name' => $task->area->name, 'confidence' => $confidence - 10] : null,
                ])->filter()->all();
            })
            ->values();
    }
}
