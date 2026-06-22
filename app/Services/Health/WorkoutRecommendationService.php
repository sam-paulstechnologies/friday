<?php

namespace App\Services\Health;

use App\Models\DailyHealthLog;

class WorkoutRecommendationService
{
    public function recommend(?DailyHealthLog $log): array
    {
        if (! $log) {
            return [
                'focus' => 'recovery',
                'text' => 'Log sleep and energy first. Until then, choose a light walk or mobility.',
                'intensity' => 'low',
            ];
        }

        $focus = $log->workout_focus ?: 'recovery';

        return match ($focus) {
            'strength' => [
                'focus' => 'strength',
                'text' => 'Strength session approved. Keep form clean and avoid ego lifting.',
                'intensity' => 'medium',
            ],
            'cardio' => [
                'focus' => 'cardio',
                'text' => 'Do a light/moderate cardio session or treadmill walk.',
                'intensity' => 'medium',
            ],
            'mobility' => [
                'focus' => 'mobility',
                'text' => 'Do mobility and stretching. Keep it easy.',
                'intensity' => 'low',
            ],
            'rest' => [
                'focus' => 'rest',
                'text' => 'Rest day recommended. Protect recovery.',
                'intensity' => 'low',
            ],
            default => [
                'focus' => 'recovery',
                'text' => 'Choose recovery work: easy walk, mobility, or very light treadmill.',
                'intensity' => 'low',
            ],
        };
    }
}
