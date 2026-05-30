<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProjectMemberController extends Controller
{
    public function store(Request $request, Project $project)
    {
        Gate::authorize('update', $project);

        $workspaceUserIds = $project->workspace?->users()->pluck('users.id')->all() ?? [];
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::in($workspaceUserIds)],
            'role' => ['nullable', 'string', 'max:50'],
        ]);

        $project->members()->syncWithoutDetaching([
            (int) $data['user_id'] => [
                'role' => $data['role'] ?? 'member',
                'added_by' => $request->user()->id,
            ],
        ]);

        $member = User::find($data['user_id']);
        $project->activities()->create([
            'user_id' => $request->user()->id,
            'action' => 'member_added',
            'description' => 'Project member added.',
            'new_value' => $member?->name,
        ]);
        AuditLog::record($project->workspace_id, $request->user()->id, 'project_member_added', $project, [
            'project_name' => $project->name,
            'member_id' => $member?->id,
            'member_email' => $member?->email,
        ]);

        return back()->with('success', 'Project member added.');
    }

    public function destroy(Request $request, Project $project, User $user)
    {
        Gate::authorize('update', $project);

        abort_unless($project->members()->whereKey($user->id)->exists(), 404);

        $project->members()->detach($user->id);
        $project->activities()->create([
            'user_id' => $request->user()->id,
            'action' => 'member_removed',
            'description' => 'Project member removed.',
            'old_value' => $user->name,
        ]);
        AuditLog::record($project->workspace_id, $request->user()->id, 'project_member_removed', $project, [
            'project_name' => $project->name,
            'member_id' => $user->id,
            'member_email' => $user->email,
        ]);

        return back()->with('success', 'Project member removed.');
    }
}
