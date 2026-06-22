<?php

namespace App\Services\Health;

use App\Models\Medication;
use App\Models\MedicationLog;
use App\Models\User;

class MedicationStatusService
{
    public function todayLog(Medication $medication, ?string $source = 'web'): MedicationLog
    {
        return MedicationLog::firstOrCreate(
            [
                'medication_id' => $medication->id,
                'user_id' => $medication->user_id,
                'log_date' => now()->toDateString(),
            ],
            [
                'workspace_id' => $medication->workspace_id,
                'status' => 'pending',
                'source' => $source,
            ],
        );
    }

    public function markTaken(Medication $medication, ?string $source = 'web'): MedicationLog
    {
        $log = $this->todayLog($medication, $source);
        $log->update([
            'status' => 'taken',
            'confirmed_at' => now(),
            'snoozed_until' => null,
            'source' => $source,
        ]);

        return $log->refresh();
    }

    public function markSkipped(Medication $medication, ?string $source = 'web'): MedicationLog
    {
        $log = $this->todayLog($medication, $source);
        $log->update([
            'status' => 'skipped',
            'confirmed_at' => null,
            'snoozed_until' => null,
            'source' => $source,
        ]);

        return $log->refresh();
    }

    public function markSnoozed(Medication $medication, int $minutes = 30, ?string $source = 'web'): MedicationLog
    {
        $log = $this->todayLog($medication, $source);
        $log->update([
            'status' => 'snoozed',
            'confirmed_at' => null,
            'snoozed_until' => now()->addMinutes($minutes),
            'source' => $source,
        ]);

        return $log->refresh();
    }

    public function statusForUser(User $user): array
    {
        $medications = Medication::query()
            ->with(['logs' => fn ($query) => $query->whereDate('log_date', now()->toDateString())])
            ->where('user_id', $user->id)
            ->where('active', true)
            ->orderBy('schedule_time')
            ->orderBy('name')
            ->get();

        $items = $medications->map(function (Medication $medication): array {
            $log = $medication->logs->first();

            return [
                'id' => $medication->id,
                'name' => $medication->name,
                'dosage' => $medication->dosage,
                'schedule_time' => $medication->schedule_time,
                'status' => $log?->status ?? 'pending',
                'confirmed_at' => $log?->confirmed_at?->toDateTimeString(),
                'snoozed_until' => $log?->snoozed_until?->toDateTimeString(),
            ];
        })->values();

        return [
            'items' => $items,
            'pending_count' => $items->whereIn('status', ['pending', 'snoozed'])->count(),
            'taken_count' => $items->where('status', 'taken')->count(),
            'status_label' => $items->isEmpty()
                ? 'No medications configured'
                : ($items->whereIn('status', ['pending', 'snoozed'])->count() > 0 ? 'Medication pending' : 'Medication confirmed'),
        ];
    }
}
