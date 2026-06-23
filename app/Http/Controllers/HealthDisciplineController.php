<?php

namespace App\Http\Controllers;

use App\Models\DailyHealthLog;
use App\Models\CalendarConnection;
use App\Models\Medication;
use App\Models\MedicationDoseLog;
use App\Models\WorkoutLog;
use App\Services\Calendar\GoogleCalendarService;
use App\Services\Health\HealthReadinessService;
use App\Services\Health\HealthSummaryService;
use App\Services\Health\MedicationReminderService;
use App\Services\Health\MedicationStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class HealthDisciplineController extends Controller
{
    public function index(Request $request, HealthSummaryService $summary, MedicationReminderService $reminders, GoogleCalendarService $googleCalendarService): Response
    {
        $user = $request->user();
        $calendarConnection = CalendarConnection::query()
            ->where('user_id', $user->id)
            ->where('provider', 'google')
            ->where('is_active', true)
            ->latest()
            ->first();

        return Inertia::render('Health/Index', [
            'health' => $summary->forUser($user),
            'medicationDoseStatus' => $reminders->statusForUser($user),
            'googleCalendar' => [
                'enabled' => $googleCalendarService->enabled(),
                'configured' => $googleCalendarService->configured(),
                'connected' => (bool) $calendarConnection,
                'connect_url' => route('settings.integrations.google.connect'),
                'provider_account_email' => $calendarConnection?->provider_account_email,
            ],
            'medications' => Medication::query()
                ->where('user_id', $user->id)
                ->where('active', true)
                ->orderBy('schedule_time')
                ->orderBy('name')
                ->get()
                ->map(fn (Medication $medication) => [
                    'id' => $medication->id,
                    'name' => $medication->name,
                    'dosage' => $medication->dosage,
                    'schedule_time' => $medication->schedule_time,
                    'notes' => $medication->notes,
                ]),
            'recentWorkouts' => WorkoutLog::query()
                ->where('user_id', $user->id)
                ->latest('workout_date')
                ->limit(7)
                ->get()
                ->map(fn (WorkoutLog $workout) => $this->workoutResource($workout)),
        ]);
    }

    public function storeDailyLog(Request $request, HealthReadinessService $readiness): RedirectResponse
    {
        $workspaceIds = $request->user()->accessibleWorkspaceIds();
        $data = $request->validate([
            'workspace_id' => ['nullable', 'integer', Rule::in($workspaceIds)],
            'log_date' => ['nullable', 'date'],
            'sleep_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'sleep_quality' => ['nullable', 'integer', 'min:1', 'max:5'],
            'energy_score' => ['nullable', 'integer', 'min:1', 'max:5'],
            'mood_score' => ['nullable', 'integer', 'min:1', 'max:5'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'source' => ['nullable', 'string', 'max:50'],
        ]);

        $workspaceId = $data['workspace_id'] ?? collect($workspaceIds)->first();
        $log = DailyHealthLog::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'workspace_id' => $workspaceId,
                'log_date' => $data['log_date'] ?? now()->toDateString(),
            ],
            [
                'sleep_hours' => $data['sleep_hours'] ?? null,
                'sleep_quality' => $data['sleep_quality'] ?? null,
                'energy_score' => $data['energy_score'] ?? null,
                'mood_score' => $data['mood_score'] ?? null,
                'notes' => $data['notes'] ?? null,
                'source' => $data['source'] ?? 'web',
            ],
        );

        $readiness->applyToLog($log->load('user'));

        return back()->with('success', 'Health check saved.');
    }

    public function storeMedication(Request $request): RedirectResponse
    {
        $workspaceIds = $request->user()->accessibleWorkspaceIds();
        $data = $request->validate([
            'workspace_id' => ['nullable', 'integer', Rule::in($workspaceIds)],
            'name' => ['required', 'string', 'max:255'],
            'dosage' => ['nullable', 'string', 'max:255'],
            'schedule_time' => ['nullable', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        Medication::create([
            ...$data,
            'user_id' => $request->user()->id,
            'workspace_id' => $data['workspace_id'] ?? collect($workspaceIds)->first(),
            'active' => true,
        ]);

        return back()->with('success', 'Medication added.');
    }

    public function markMedicationTaken(Request $request, Medication $medication, MedicationStatusService $status): RedirectResponse
    {
        $this->authorizeMedication($request, $medication);
        $status->markTaken($medication, 'web');
        $this->syncMedicationStatus($request, 'taken');

        return back()->with('success', 'Medication marked taken.');
    }

    public function skipMedication(Request $request, Medication $medication, MedicationStatusService $status): RedirectResponse
    {
        $this->authorizeMedication($request, $medication);
        $status->markSkipped($medication, 'web');
        $this->syncMedicationStatus($request, 'skipped');

        return back()->with('success', 'Medication skipped.');
    }

    public function snoozeMedication(Request $request, Medication $medication, MedicationStatusService $status): RedirectResponse
    {
        $this->authorizeMedication($request, $medication);
        $status->markSnoozed($medication, 30, 'web');
        $this->syncMedicationStatus($request, 'snoozed');

        return back()->with('success', 'Medication snoozed.');
    }

    public function markDoseTaken(Request $request, MedicationDoseLog $doseLog, MedicationReminderService $reminders): RedirectResponse
    {
        $this->authorizeDoseLog($request, $doseLog);
        $reminders->markTaken($doseLog, 'web', 'web');

        return back()->with('success', 'Dose marked taken.');
    }

    public function skipDose(Request $request, MedicationDoseLog $doseLog, MedicationReminderService $reminders): RedirectResponse
    {
        $this->authorizeDoseLog($request, $doseLog);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $reminders->skip($doseLog, $data['reason'], 'web', 'web');

        return back()->with('success', 'Dose skipped.');
    }

    public function snoozeDose(Request $request, MedicationDoseLog $doseLog, MedicationReminderService $reminders): RedirectResponse
    {
        $this->authorizeDoseLog($request, $doseLog);
        $data = $request->validate([
            'minutes' => ['nullable', 'integer', 'min:5', 'max:240'],
        ]);
        $reminders->snooze($doseLog, (int) ($data['minutes'] ?? 30), 'web', 'web');

        return back()->with('success', 'Dose snoozed.');
    }

    public function storeWorkout(Request $request): RedirectResponse
    {
        $workspaceIds = $request->user()->accessibleWorkspaceIds();
        $data = $request->validate([
            'workspace_id' => ['nullable', 'integer', Rule::in($workspaceIds)],
            'workout_date' => ['nullable', 'date'],
            'planned_focus' => ['nullable', Rule::in(['cardio', 'strength', 'mobility', 'recovery', 'rest'])],
            'actual_focus' => ['nullable', Rule::in(['cardio', 'strength', 'mobility', 'recovery', 'rest'])],
            'status' => ['nullable', Rule::in(['planned', 'completed', 'skipped', 'rest'])],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'intensity' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);

        WorkoutLog::create([
            ...$data,
            'user_id' => $request->user()->id,
            'workspace_id' => $data['workspace_id'] ?? collect($workspaceIds)->first(),
            'workout_date' => $data['workout_date'] ?? now()->toDateString(),
            'status' => $data['status'] ?? 'planned',
            'source' => 'web',
        ]);

        return back()->with('success', 'Workout logged.');
    }

    public function completeWorkout(Request $request, WorkoutLog $workoutLog): RedirectResponse
    {
        $this->authorizeWorkout($request, $workoutLog);
        $data = $request->validate([
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'actual_focus' => ['nullable', Rule::in(['cardio', 'strength', 'mobility', 'recovery', 'rest'])],
            'intensity' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);

        $workoutLog->update([
            ...$data,
            'status' => 'completed',
        ]);

        return back()->with('success', 'Workout completed.');
    }

    public function skipWorkout(Request $request, WorkoutLog $workoutLog): RedirectResponse
    {
        $this->authorizeWorkout($request, $workoutLog);
        $workoutLog->update([
            'status' => 'skipped',
            'notes' => $request->string('notes')->toString() ?: $workoutLog->notes,
        ]);

        return back()->with('success', 'Workout skipped.');
    }

    private function syncMedicationStatus(Request $request, string $status): void
    {
        DailyHealthLog::query()
            ->where('user_id', $request->user()->id)
            ->whereDate('log_date', now()->toDateString())
            ->latest()
            ->first()
            ?->update(['medication_status' => $status]);
    }

    private function authorizeMedication(Request $request, Medication $medication): void
    {
        abort_unless($medication->user_id === $request->user()->id, 403);
        abort_unless(! $medication->workspace_id || $request->user()->canAccessWorkspace($medication->workspace_id), 403);
    }

    private function authorizeDoseLog(Request $request, MedicationDoseLog $doseLog): void
    {
        abort_unless($doseLog->user_id === $request->user()->id, 403);
        abort_unless(! $doseLog->workspace_id || $request->user()->canAccessWorkspace($doseLog->workspace_id), 403);
    }

    private function authorizeWorkout(Request $request, WorkoutLog $workoutLog): void
    {
        abort_unless($workoutLog->user_id === $request->user()->id, 403);
        abort_unless(! $workoutLog->workspace_id || $request->user()->canAccessWorkspace($workoutLog->workspace_id), 403);
    }

    private function workoutResource(WorkoutLog $workout): array
    {
        return [
            'id' => $workout->id,
            'workout_date' => $workout->workout_date?->toDateString(),
            'planned_focus' => $workout->planned_focus,
            'actual_focus' => $workout->actual_focus,
            'status' => $workout->status,
            'duration_minutes' => $workout->duration_minutes,
            'intensity' => $workout->intensity,
            'notes' => $workout->notes,
        ];
    }
}
