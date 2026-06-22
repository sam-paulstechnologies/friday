<?php

namespace App\Services\Health;

use App\Models\DailyHealthLog;
use App\Models\User;
use App\Models\WorkoutLog;

class HealthSummaryService
{
    public function __construct(
        private readonly MedicationStatusService $medications,
        private readonly WorkoutRecommendationService $workouts,
    ) {}

    public function forUser(User $user): array
    {
        $today = DailyHealthLog::query()
            ->where('user_id', $user->id)
            ->whereDate('log_date', now()->toDateString())
            ->latest()
            ->first();

        $workout = WorkoutLog::query()
            ->where('user_id', $user->id)
            ->whereDate('workout_date', now()->toDateString())
            ->latest()
            ->first();

        return [
            'today' => $today ? [
                'id' => $today->id,
                'log_date' => $today->log_date?->toDateString(),
                'sleep_hours' => $today->sleep_hours !== null ? (float) $today->sleep_hours : null,
                'sleep_quality' => $today->sleep_quality,
                'energy_score' => $today->energy_score,
                'mood_score' => $today->mood_score,
                'gym_readiness_score' => $today->gym_readiness_score,
                'gym_approved' => $today->gym_approved,
                'gym_recommendation' => $today->gym_recommendation,
                'workout_focus' => $today->workout_focus,
                'medication_status' => $today->medication_status,
                'notes' => $today->notes,
            ] : null,
            'medication' => $this->medications->statusForUser($user),
            'workout' => $workout ? [
                'id' => $workout->id,
                'workout_date' => $workout->workout_date?->toDateString(),
                'planned_focus' => $workout->planned_focus,
                'actual_focus' => $workout->actual_focus,
                'status' => $workout->status,
                'duration_minutes' => $workout->duration_minutes,
                'intensity' => $workout->intensity,
                'notes' => $workout->notes,
            ] : null,
            'recommendation' => $this->workouts->recommend($today),
        ];
    }
}
