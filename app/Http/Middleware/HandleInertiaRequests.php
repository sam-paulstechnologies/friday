<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Services\Inbox\InboxService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'notifications' => [
                'unread_count' => $request->user()?->unreadNotifications()->count() ?? 0,
            ],
            'inbox' => [
                'open_count' => $request->user()
                    ? app(InboxService::class)->openCount($request->user())
                    : 0,
            ],
            'workspace' => [
                'name' => $request->user()
                    ? Workspace::query()
                        ->whereIn('id', $request->user()->accessibleWorkspaceIds())
                        ->orderBy('id')
                        ->value('name')
                    : null,
            ],
            // Shared so a failed action can actually say what went wrong
            // instead of the UI silently reporting success.
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                // Structured Quick Capture result: what was saved and where.
                'capture' => fn () => $request->session()->get('capture'),
            ],
        ];
    }
}
