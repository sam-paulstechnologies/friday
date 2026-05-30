<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\CalendarConnection;
use App\Models\Workspace;
use App\Services\Calendar\CalendarSyncService;
use App\Services\Calendar\GoogleCalendarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationSettingsController extends Controller
{
    public function index(Request $request, GoogleCalendarService $googleCalendarService): Response
    {
        $connection = $request->user()
            ->calendarConnections()
            ->where('provider', 'google')
            ->latest()
            ->first();

        return Inertia::render('Settings/Integrations/Index', [
            'googleCalendar' => [
                'enabled' => $googleCalendarService->enabled(),
                'configured' => $googleCalendarService->configured(),
                'connection' => $connection ? [
                    'id' => $connection->id,
                    'connected' => $connection->is_active,
                    'provider_account_email' => $connection->provider_account_email,
                    'last_synced_at' => $connection->last_synced_at?->toDateTimeString(),
                    'workspace' => $connection->workspace?->only(['id', 'name']),
                ] : null,
                'logs' => $connection?->logs()
                    ->latest()
                    ->limit(8)
                    ->get()
                    ->map(fn ($log) => [
                        'id' => $log->id,
                        'direction' => $log->direction,
                        'status' => $log->status,
                        'message' => $log->message,
                        'created_at' => $log->created_at?->toDateTimeString(),
                        'metadata' => $log->metadata ?? [],
                    ])
                    ->values() ?? [],
            ],
        ]);
    }

    public function connect(Request $request, GoogleCalendarService $googleCalendarService): RedirectResponse
    {
        if (! $googleCalendarService->configured()) {
            return back()->with('error', 'Google Calendar is disabled or not configured.');
        }

        $workspace = $this->selectedWorkspace($request);
        $state = Str::random(40);
        $request->session()->put('google_calendar_oauth_state', $state);
        $request->session()->put('google_calendar_workspace_id', $workspace?->id);

        return redirect()->away($googleCalendarService->authUrl($state));
    }

    public function callback(Request $request, GoogleCalendarService $googleCalendarService): RedirectResponse
    {
        if (! $googleCalendarService->configured()) {
            return redirect()->route('settings.integrations.index')->with('error', 'Google Calendar is disabled or not configured.');
        }

        if ($request->filled('error')) {
            return redirect()->route('settings.integrations.index')->with('error', 'Google Calendar connection was cancelled.');
        }

        $expectedState = $request->session()->pull('google_calendar_oauth_state');
        $workspaceId = $request->session()->pull('google_calendar_workspace_id');

        abort_unless($expectedState && hash_equals($expectedState, (string) $request->query('state')), 403);

        $data = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $token = $googleCalendarService->exchangeCode($data['code']);
        $expiresIn = isset($token['expires_in']) ? (int) $token['expires_in'] : null;

        CalendarConnection::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'provider' => 'google',
                'provider_account_email' => $token['provider_account_email'] ?? $token['email'] ?? $request->user()->email,
            ],
            [
                'workspace_id' => $workspaceId,
                'access_token' => $token['access_token'] ?? null,
                'refresh_token' => $token['refresh_token'] ?? null,
                'token_expires_at' => $expiresIn ? now()->addSeconds($expiresIn) : null,
                'scopes' => isset($token['scope']) ? explode(' ', $token['scope']) : config('services.google_calendar.scopes', []),
                'is_active' => true,
            ],
        );

        return redirect()->route('settings.integrations.index')->with('success', 'Google Calendar connected.');
    }

    public function disconnect(Request $request, CalendarConnection $calendarConnection): RedirectResponse
    {
        abort_unless((int) $calendarConnection->user_id === (int) $request->user()->id, 403);

        $calendarConnection->forceFill([
            'access_token' => null,
            'refresh_token' => null,
            'is_active' => false,
        ])->save();

        return back()->with('success', 'Google Calendar disconnected.');
    }

    public function sync(Request $request, CalendarSyncService $calendarSyncService): RedirectResponse
    {
        $connection = $request->user()
            ->calendarConnections()
            ->where('provider', 'google')
            ->where('is_active', true)
            ->latest()
            ->first();

        if (! $connection) {
            return back()->with('error', 'Connect Google Calendar before syncing.');
        }

        $counts = $calendarSyncService->syncConnection($connection);

        return back()->with('success', "Google Calendar sync finished: {$counts['created']} created, {$counts['updated']} updated, {$counts['failed']} failed.");
    }

    private function selectedWorkspace(Request $request): ?Workspace
    {
        $workspaceIds = $request->user()->accessibleWorkspaceIds();

        if ($workspaceIds === []) {
            return null;
        }

        return Workspace::query()
            ->whereIn('id', $workspaceIds)
            ->when($request->integer('workspace_id'), fn ($query, int $workspaceId) => $query->whereKey($workspaceId))
            ->orderBy('name')
            ->first();
    }
}
