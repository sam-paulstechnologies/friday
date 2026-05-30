<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $workspace = $this->selectedWorkspace($request);

        Gate::authorize('viewSettings', $workspace);

        $workspace->load(['creator:id,name,email']);

        return Inertia::render('Settings/Workspace/Edit', [
            'workspace' => $this->workspaceResource($workspace),
            'members' => $this->members($workspace),
            'auditLogs' => $this->auditLogs($workspace),
            'roles' => $this->roles(),
            'canManageMembers' => Gate::allows('manageMembers', $workspace),
            'canManageRoles' => Gate::allows('manageRoles', $workspace),
        ]);
    }

    public function update(Request $request)
    {
        $workspace = $this->selectedWorkspace($request);

        Gate::authorize('update', $workspace);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $oldName = $workspace->name;
        $workspace->update(['name' => $data['name']]);

        AuditLog::record($workspace->id, $request->user()->id, 'workspace_settings_updated', $workspace, [
            'old_name' => $oldName,
            'new_name' => $workspace->name,
        ]);

        return back()->with('success', 'Workspace settings updated.');
    }

    public function addMember(Request $request)
    {
        $workspace = $this->selectedWorkspace($request);

        Gate::authorize('manageMembers', $workspace);

        $data = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'role' => ['required', Rule::in($this->roles())],
        ]);

        $user = User::query()->where('email', $data['email'])->firstOrFail();

        $workspace->users()->syncWithoutDetaching([
            $user->id => [
                'role' => $data['role'],
                'joined_at' => now(),
            ],
        ]);

        AuditLog::record($workspace->id, $request->user()->id, 'workspace_member_added', $user, [
            'member_id' => $user->id,
            'member_email' => $user->email,
            'role' => $data['role'],
        ]);

        return back()->with('success', 'Workspace member added.');
    }

    public function updateMember(Request $request, User $user)
    {
        $workspace = $this->selectedWorkspace($request);

        Gate::authorize('manageRoles', $workspace);
        abort_unless($workspace->users()->whereKey($user->id)->exists(), 404);

        $data = $request->validate([
            'role' => ['required', Rule::in($this->roles())],
        ]);

        $oldRole = $user->workspaceRole($workspace->id);

        if ($oldRole === 'owner' && $data['role'] !== 'owner') {
            abort_if($this->ownerCount($workspace) <= 1, 422, 'A workspace must keep at least one owner.');
        }

        $workspace->users()->updateExistingPivot($user->id, ['role' => $data['role']]);

        AuditLog::record($workspace->id, $request->user()->id, 'workspace_role_changed', $user, [
            'member_id' => $user->id,
            'member_email' => $user->email,
            'old_role' => $oldRole,
            'new_role' => $data['role'],
        ]);

        return back()->with('success', 'Workspace role updated.');
    }

    public function removeMember(Request $request, User $user)
    {
        $workspace = $this->selectedWorkspace($request);

        Gate::authorize('manageMembers', $workspace);
        abort_unless($workspace->users()->whereKey($user->id)->exists(), 404);

        if ($user->workspaceRole($workspace->id) === 'owner') {
            abort_if($this->ownerCount($workspace) <= 1, 422, 'A workspace must keep at least one owner.');
        }

        $workspace->users()->detach($user->id);

        AuditLog::record($workspace->id, $request->user()->id, 'workspace_member_removed', $user, [
            'member_id' => $user->id,
            'member_email' => $user->email,
        ]);

        return back()->with('success', 'Workspace member removed.');
    }

    private function selectedWorkspace(Request $request): Workspace
    {
        $workspaceIds = $request->user()->accessibleWorkspaceIds();
        abort_if($workspaceIds === [], 403);

        return Workspace::query()
            ->whereIn('id', $workspaceIds)
            ->when($request->integer('workspace_id'), fn ($query, int $workspaceId) => $query->whereKey($workspaceId))
            ->orderBy('name')
            ->firstOrFail();
    }

    private function members(Workspace $workspace): array
    {
        return $workspace->users()
            ->select(['users.id', 'users.name', 'users.email'])
            ->orderBy('users.name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->pivot?->role ?? 'member',
                'joined_at' => $user->pivot?->joined_at,
                'is_owner' => ($user->pivot?->role ?? null) === 'owner',
            ])
            ->values()
            ->all();
    }

    private function auditLogs(Workspace $workspace): array
    {
        return $workspace->auditLogs()
            ->with('actor:id,name')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'metadata' => $log->metadata ?? [],
                'created_at' => $log->created_at?->toDateTimeString(),
                'actor' => $log->actor?->only(['id', 'name']),
            ])
            ->all();
    }

    private function workspaceResource(Workspace $workspace): array
    {
        return [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'slug' => $workspace->slug,
            'owner' => $workspace->creator?->only(['id', 'name', 'email']),
        ];
    }

    private function roles(): array
    {
        return ['owner', 'admin', 'member', 'viewer'];
    }

    private function ownerCount(Workspace $workspace): int
    {
        return $workspace->users()->wherePivot('role', 'owner')->count();
    }
}
