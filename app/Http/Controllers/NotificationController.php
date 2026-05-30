<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Notifications/Index', [
            'unreadNotifications' => $this->notificationResources($request->user()
                ->unreadNotifications()
                ->latest()
                ->limit(100)
                ->get()),
            'readNotifications' => $this->notificationResources($request->user()
                ->notifications()
                ->whereNotNull('read_at')
                ->latest()
                ->limit(100)
                ->get()),
        ]);
    }

    public function read(Request $request, DatabaseNotification $notification)
    {
        Gate::authorize('update', $notification);

        $notification->markAsRead();

        return back()->with('success', 'Notification marked as read.');
    }

    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('success', 'All notifications marked as read.');
    }

    private function notificationResources($notifications)
    {
        return $notifications->map(fn (DatabaseNotification $notification) => [
            'id' => $notification->id,
            'type' => $notification->data['event_type'] ?? class_basename($notification->type),
            'title' => $notification->data['title'] ?? 'Notification',
            'message' => $notification->data['message'] ?? '',
            'task_id' => $notification->data['task_id'] ?? null,
            'project_id' => $notification->data['project_id'] ?? null,
            'action_url' => $notification->data['action_url'] ?? null,
            'created_at' => $notification->created_at?->toDateTimeString(),
            'read_at' => $notification->read_at?->toDateTimeString(),
            'unread' => is_null($notification->read_at),
        ])->values();
    }
}
