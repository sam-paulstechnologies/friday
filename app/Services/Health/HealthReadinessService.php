<?php

namespace App\Services\Health;

use App\Models\DailyHealthLog;
use App\Models\User;
use App\Models\WorkoutLog;

class HealthReadinessService
{
    public function evaluate(?float $sleepHours, ?int $energyScore, ?int $moodScore = null, ?User $user = null): array
    {
        $score = 3;
        $reasons = [];

        if ($sleepHours !== null) {
            if ($sleepHours < 5) {
                $score -= 2;
                $reasons[] = 'Sleep is under 5 hours.';
            } elseif ($sleepHours < 6.5) {
                $score -= 1;
                $reasons[] = 'Sleep is between 5 and 6.5 hours.';
            } else {
                $score += 1;
                $reasons[] = 'Sleep is above 6.5 hours.';
            }
        }

        if ($energyScore !== null) {
            if ($energyScore <= 2) {
                $score -= 2;
                $reasons[] = 'Energy is low.';
            } elseif ($energyScore >= 4) {
                $score += 1;
                $reasons[] = 'Energy is solid.';
            }
        }

        if ($moodScore !== null && $moodScore <= 2) {
            $score -= 1;
            $reasons[] = 'Mood is low.';
        }

        if ($user && $this->recentSkippedWorkouts($user) >= 2) {
            $score = max(2, $score);
            $reasons[] = 'Multiple recent skipped workouts; restart with low friction.';
        }

        $score = max(1, min(5, $score));
        $gymApproved = $score >= 3 && ! ($sleepHours !== null && $sleepHours < 5) && ! ($energyScore !== null && $energyScore <= 2);

        return [
            'gym_readiness_score' => $score,
            'gym_approved' => $gymApproved,
            'workout_focus' => $this->focus($score, $sleepHours, $energyScore),
            'gym_recommendation' => $this->recommendation($score, $sleepHours, $energyScore, $moodScore, $gymApproved),
            'reasons' => $reasons,
        ];
    }

    public function applyToLog(DailyHealthLog $log): DailyHealthLog
    {
        $result = $this->evaluate(
            $log->sleep_hours !== null ? (float) $log->sleep_hours : null,
            $log->energy_score,
            $log->mood_score,
            $log->user,
        );

        $log->update([
            'gym_readiness_score' => $result['gym_readiness_score'],
            'gym_approved' => $result['gym_approved'],
            'workout_focus' => $result['workout_focus'],
            'gym_recommendation' => $result['gym_recommendation'],
            'metadata' => [
                ...($log->metadata ?? []),
                'readiness_reasons' => $result['reasons'],
            ],
        ]);

        return $log->refresh();
    }

    private function focus(int $score, ?float $sleepHours, ?int $energyScore): string
    {
        if ($sleepHours !== null && $sleepHours < 5) {
            return 'rest';
        }

        if ($energyScore !== null && $energyScore <= 2) {
            return 'recovery';
        }

        if ($score <= 2) {
            return 'mobility';
        }

        if ($score === 3) {
            return 'cardio';
        }

        return 'strength';
    }

    private function recommendation(int $score, ?float $sleepHours, ?int $energyScore, ?int $moodScore, bool $gymApproved): string
    {
        if (! $gymApproved) {
            return 'Skip heavy gym today. Do rest, a short walk, treadmill, or mobility only.';
        }

        if (($sleepHours !== null && $sleepHours < 6.5) || ($energyScore !== null && $energyScore <= 3) || ($moodScore !== null && $moodScore <= 2)) {
            return 'Gym is approved, but keep it light or moderate. Finish with energy left.';
        }

        if ($score >= 4) {
            return 'Gym approved. Normal workout is fine today.';
        }

        return 'Do an easy session. The goal is consistency, not intensity.';
    }

    private function recentSkippedWorkouts(User $user): int
    {
        return WorkoutLog::query()
            ->where('user_id', $user->id)
            ->where('status', 'skipped')
            ->whereDate('workout_date', '>=', now()->subDays(10)->toDateString())
            ->count();
    }
}
