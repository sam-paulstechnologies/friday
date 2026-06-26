<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\MedicationDoseLog;
use App\Models\MiriamMobileToken;
use App\Models\MiriamReminder;
use App\Services\Mobile\MiriamMobileApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MiriamMobileController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $user = \App\Models\User::query()->where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'The provided credentials are invalid.']);
        }

        $plainToken = Str::random(72);
        MiriamMobileToken::create([
            'user_id' => $user->id,
            'name' => $data['device_name'] ?? 'Miriam mobile',
            'token_hash' => hash('sha256', $plainToken),
            'abilities' => ['mobile'],
            'last_used_at' => now(),
        ]);

        return response()->json([
            'token' => $plainToken,
            'token_type' => 'Bearer',
            'user' => $this->userResource($user),
        ]);
    }

    public function me(Request $request, MiriamMobileApiService $service): JsonResponse
    {
        return response()->json([
            'user' => $this->userResource($request->user()),
            'dashboard' => $service->dashboard($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->attributes->get('miriam_mobile_token')?->delete();

        return response()->json(['ok' => true]);
    }

    public function chat(Request $request, MiriamMobileApiService $service): JsonResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:2000']]);

        return response()->json($service->chat($request->user(), $data['message']));
    }

    public function reminders(Request $request, MiriamMobileApiService $service): JsonResponse
    {
        return response()->json(['data' => $service->pendingReminders($request->user())]);
    }

    public function reminderDone(Request $request, MiriamReminder $reminder, MiriamMobileApiService $service): JsonResponse
    {
        return response()->json(['data' => $service->updateReminder($request->user(), $reminder, 'done')]);
    }

    public function reminderSnooze(Request $request, MiriamReminder $reminder, MiriamMobileApiService $service): JsonResponse
    {
        return response()->json(['data' => $service->updateReminder($request->user(), $reminder, 'snooze')]);
    }

    public function reminderCancel(Request $request, MiriamReminder $reminder, MiriamMobileApiService $service): JsonResponse
    {
        return response()->json(['data' => $service->updateReminder($request->user(), $reminder, 'cancel')]);
    }

    public function medicationStatus(Request $request, MiriamMobileApiService $service): JsonResponse
    {
        return response()->json($service->medicationStatus($request->user()));
    }

    public function medicationTaken(Request $request, MedicationDoseLog $doseLog, MiriamMobileApiService $service): JsonResponse
    {
        return response()->json(['data' => $service->updateDose($request->user(), $doseLog, 'taken')]);
    }

    public function medicationSnooze(Request $request, MedicationDoseLog $doseLog, MiriamMobileApiService $service): JsonResponse
    {
        $data = $request->validate(['minutes' => ['nullable', 'integer', 'min:5', 'max:240']]);

        return response()->json(['data' => $service->updateDose($request->user(), $doseLog, 'snooze', $data)]);
    }

    public function medicationSkip(Request $request, MedicationDoseLog $doseLog, MiriamMobileApiService $service): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return response()->json(['data' => $service->updateDose($request->user(), $doseLog, 'skip', $data)]);
    }

    public function agenda(Request $request, MiriamMobileApiService $service, string $period): JsonResponse
    {
        abort_unless(in_array($period, ['today', 'tomorrow', 'upcoming'], true), 404);

        return response()->json($service->agenda($request->user(), $period));
    }

    public function agendaToday(Request $request, MiriamMobileApiService $service): JsonResponse
    {
        return $this->agenda($request, $service, 'today');
    }

    public function agendaTomorrow(Request $request, MiriamMobileApiService $service): JsonResponse
    {
        return $this->agenda($request, $service, 'tomorrow');
    }

    public function agendaUpcoming(Request $request, MiriamMobileApiService $service): JsonResponse
    {
        return $this->agenda($request, $service, 'upcoming');
    }

    public function developmentStatus(Request $request, MiriamMobileApiService $service): JsonResponse
    {
        return response()->json($service->developmentStatus($request->user()));
    }

    private function userResource($user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
}
