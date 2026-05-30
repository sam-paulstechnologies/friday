<?php

namespace App\Services\Ai;

use App\Models\AiTaskRecommendation;
use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AiRecommendationService
{
    public function storeFromPlanner(array $suggestions, ?int $userId, string $source, ?string $prompt = null, ?string $rawResponse = null): Collection
    {
        return collect($suggestions)
            ->flatMap(function (array $suggestion) use ($userId, $source, $prompt, $rawResponse): array {
                $task = Task::find($suggestion['task_id'] ?? null);

                if (! $task) {
                    return [];
                }

                $rows = [];

                foreach (['priority', 'status', 'due_date'] as $field) {
                    $suggestedKey = 'suggested_'.$field;

                    if (! array_key_exists($suggestedKey, $suggestion) || $suggestion[$suggestedKey] === null || $suggestion[$suggestedKey] === '') {
                        continue;
                    }

                    $current = $this->normalize($task->{$field});
                    $suggested = $this->normalize($suggestion[$suggestedKey]);

                    if ($current === $suggested) {
                        continue;
                    }

                    $rows[] = AiTaskRecommendation::create([
                        'user_id' => $userId,
                        'task_id' => $task->id,
                        'recommendation_type' => $field,
                        'current_value' => $current,
                        'suggested_value' => $suggested,
                        'reason' => (string) ($suggestion['reason'] ?? 'AI Brain recommendation.'),
                        'confidence' => isset($suggestion['confidence']) ? (int) $suggestion['confidence'] : null,
                        'status' => 'pending',
                        'source' => $source,
                        'raw_prompt' => $prompt ? Str::limit($prompt, 2000, '') : null,
                        'raw_response' => $rawResponse ? Str::limit($rawResponse, 8000, '') : null,
                    ]);
                }

                return $rows;
            })
            ->values();
    }

    public function pendingForUser(?int $userId): Collection
    {
        return AiTaskRecommendation::query()
            ->with('task:id,title,priority,status,due_date')
            ->where('status', 'pending')
            ->when($userId, fn ($query) => $query->where('user_id', $userId), fn ($query) => $query->whereNull('user_id'))
            ->latest()
            ->get()
            ->sortBy('id')
            ->values();
    }

    public function formatPending(?int $userId): string
    {
        $pending = $this->pendingForUser($userId);

        if ($pending->isEmpty()) {
            return 'No pending AI recommendations.';
        }

        return "Pending AI recommendations:\n".$pending
            ->map(fn (AiTaskRecommendation $recommendation, int $index): string => $this->line($recommendation, $index + 1))
            ->implode("\n\n");
    }

    public function applySelection(?int $userId, string $selection): string
    {
        $selected = $this->selectPending($userId, $selection);

        if ($selected->isEmpty()) {
            return 'No matching pending AI recommendations found.';
        }

        $applied = 0;

        DB::transaction(function () use ($selected, &$applied): void {
            foreach ($selected as $recommendation) {
                $task = $recommendation->task()->lockForUpdate()->first();

                if (! $task || $recommendation->status !== 'pending') {
                    continue;
                }

                $field = $recommendation->recommendation_type;
                $oldValue = $this->normalize($task->{$field});
                $newValue = $this->castValue($field, $recommendation->suggested_value);

                $update = [$field => $newValue];
                if ($field === 'status') {
                    $update['completed_at'] = $newValue === 'completed' ? ($task->completed_at ?? now()) : null;
                }

                $task->update($update);
                $recommendation->update([
                    'status' => 'applied',
                    'approved_at' => now(),
                    'applied_at' => now(),
                ]);

                $this->logActivity($task, $recommendation, $oldValue, $recommendation->suggested_value);
                $applied++;
            }
        });

        return "Applied {$applied} AI recommendation(s).";
    }

    public function rejectSelection(?int $userId, string $selection): string
    {
        $selected = $this->selectPending($userId, $selection);

        if ($selected->isEmpty()) {
            return 'No matching pending AI recommendations found.';
        }

        foreach ($selected as $recommendation) {
            $recommendation->update(['status' => 'rejected']);
        }

        return 'Rejected '.$selected->count().' AI recommendation(s).';
    }

    private function selectPending(?int $userId, string $selection): Collection
    {
        $pending = $this->pendingForUser($userId);

        if (trim(Str::lower($selection)) === 'all') {
            return $pending;
        }

        $numbers = collect(preg_split('/[,\s]+/', $selection))
            ->filter()
            ->map(fn (string $number): int => (int) $number)
            ->filter(fn (int $number): bool => $number > 0)
            ->unique()
            ->values();

        return $numbers
            ->map(fn (int $number) => $pending->get($number - 1))
            ->filter()
            ->values();
    }

    private function line(AiTaskRecommendation $recommendation, int $number): string
    {
        return "{$number}. Task #{$recommendation->task_id} - {$recommendation->task?->title}\n"
            .ucfirst($recommendation->recommendation_type).": {$recommendation->current_value} -> {$recommendation->suggested_value}\n"
            ."Reason: {$recommendation->reason}";
    }

    private function logActivity(Task $task, AiTaskRecommendation $recommendation, ?string $oldValue, ?string $newValue): void
    {
        if (! Schema::hasTable('task_activities')) {
            return;
        }

        $task->activities()->create([
            'user_id' => $recommendation->user_id,
            'action' => 'ai_brain_approval_applied',
            'description' => 'Updated via AI Brain approval. Recommendation ID: '.$recommendation->id,
            'old_value' => json_encode([$recommendation->recommendation_type => $oldValue]),
            'new_value' => json_encode([$recommendation->recommendation_type => $newValue]),
        ]);
    }

    private function castValue(string $field, ?string $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value;
    }

    private function normalize(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return (string) $value;
    }
}
