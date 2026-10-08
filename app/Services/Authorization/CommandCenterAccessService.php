<?php

namespace App\Services\Authorization;

use App\Models\Area;
use App\Models\Portfolio;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CommandCenterAccessService
{
    private const RELATIONS = ['portfolio_id' => Portfolio::class, 'project_id' => Project::class, 'task_id' => Task::class];

    public function options(User $user): array
    {
        $ids = $user->accessibleWorkspaceIds();
        $portfolios = Portfolio::query()->whereIn('workspace_id', $ids)->orderBy('name')->get()->filter(fn ($row) => $this->canView($user, $row));
        $projects = Project::query()->whereIn('workspace_id', $ids)->orderBy('name')->get()->filter(fn ($row) => $this->canView($user, $row));
        $tasks = Task::query()->whereIn('workspace_id', $ids)->active()->orderBy('title')->limit(200)->get()->filter(fn ($row) => $this->canView($user, $row))->take(200);

        return [
            // Areas are shared, read-only taxonomy. Never include global relation counts.
            'areas' => Area::query()->where('is_active', true)->orderBy('position')->get(['id', 'name']),
            'portfolios' => $portfolios->map->only(['id', 'area_id', 'name'])->values(),
            'projects' => $projects->map->only(['id', 'area_id', 'portfolio_id', 'name'])->values(),
            'tasks' => $tasks->map->only(['id', 'area_id', 'portfolio_id', 'project_id', 'title'])->values(),
        ];
    }

    public function authorizeWrite(User $user, ?Model $item = null): void
    {
        abort_unless(! $item || (int) $item->user_id === (int) $user->id, 403);
        abort_unless(collect($user->accessibleWorkspaceIds())->contains(fn ($id) => $user->canWriteWorkspace($id)), 403);
    }

    public function validateRelations(User $user, array $data): void
    {
        $rows = [];
        foreach (self::RELATIONS as $key => $class) {
            if (! empty($data[$key])) {
                $row = $class::find($data[$key]);
                if (! $row || ! $this->canView($user, $row) || ! $user->canWriteWorkspace($row->workspace_id)) {
                    $this->invalid($key);
                }
                $rows[$key] = $row;
            }
        }

        if (collect($rows)->pluck('workspace_id')->unique()->count() > 1) {
            $this->invalid('project_id');
        }

        $project = $rows['project_id'] ?? null;
        $task = $rows['task_id'] ?? null;
        $portfolio = $rows['portfolio_id'] ?? null;
        if ($project && $portfolio && (int) $project->portfolio_id !== (int) $portfolio->id) {
            $this->invalid('portfolio_id');
        }
        if ($task && $project && (int) $task->project_id !== (int) $project->id) {
            $this->invalid('task_id');
        }
        if ($task && $portfolio && (int) ($task->portfolio_id ?? $task->project?->portfolio_id) !== (int) $portfolio->id) {
            $this->invalid('portfolio_id');
        }
        if (! empty($data['area_id'])) {
            if (! Area::query()->whereKey($data['area_id'])->where('is_active', true)->exists()) {
                $this->invalid('area_id');
            }
            foreach ($rows as $row) {
                $areaId = $row->area_id ?? ($row instanceof Task ? $row->project?->area_id : null);
                if ($areaId && (int) $areaId !== (int) $data['area_id']) {
                    $this->invalid('area_id');
                }
            }
        }
    }

    public function redactInaccessibleRelations(User $user, Model $item): Model
    {
        foreach (self::RELATIONS as $key => $class) {
            $relation = str_replace('_id', '', $key);
            if (! method_exists($item, $relation)) {
                continue;
            }
            $row = $item->getRelation($relation);
            if ($row && ! $this->canView($user, $row)) {
                $item->setRelation($relation, null);
                $item->setAttribute($key, null);
            }
        }

        return $item;
    }

    private function canView(User $user, Model $row): bool
    {
        // Assignee/owner exceptions in existing policies do not bypass tenant membership here.
        return $user->canAccessWorkspace($row->workspace_id) && Gate::forUser($user)->allows('view', $row);
    }

    private function invalid(string $field): never
    {
        throw ValidationException::withMessages([$field => 'Select accessible records from the same workspace and hierarchy.']);
    }
}
