<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\Task;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class TemplateController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Templates/Index', [
            'workspaces' => Workspace::query()->select(['id', 'name'])->orderBy('name')->get(),
            'templates' => ProjectTemplate::query()
                ->with(['workspace:id,name', 'tasks' => fn ($query) => $query->orderBy('position')])
                ->latest()
                ->get()
                ->map(fn (ProjectTemplate $template) => [
                    'id' => $template->id,
                    'workspace_id' => $template->workspace_id,
                    'name' => $template->name,
                    'description' => $template->description,
                    'workspace' => $template->workspace ? [
                        'id' => $template->workspace->id,
                        'name' => $template->workspace->name,
                    ] : null,
                    'tasks' => $template->tasks->map(fn ($task) => [
                        'id' => $task->id,
                        'title' => $task->title,
                        'priority' => $task->priority,
                        'offset_days' => $task->offset_days,
                    ])->values(),
                ]),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'workspace_id' => ['required', 'integer', 'exists:workspaces,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'task_titles' => ['nullable', 'string', 'max:5000'],
        ]);

        $template = ProjectTemplate::create([
            'workspace_id' => $data['workspace_id'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        collect(explode("\n", (string) ($data['task_titles'] ?? '')))
            ->map(fn (string $title) => trim($title))
            ->filter()
            ->values()
            ->each(fn (string $title, int $index) => $template->tasks()->create([
                'title' => $title,
                'priority' => 'medium',
                'position' => $index + 1,
            ]));

        return back()->with('success', 'Template created.');
    }

    public function createProject(Request $request, ProjectTemplate $template)
    {
        $template->load(['workspace', 'tasks' => fn ($query) => $query->orderBy('position')]);

        $project = Project::create([
            'workspace_id' => $template->workspace_id,
            'owner_id' => $request->user()->id,
            'name' => $template->name.' Project',
            'slug' => $this->uniqueProjectSlug($template->name.' Project', $template->workspace_id),
            'description' => $template->description,
            'status' => 'active',
            'visibility' => 'workspace',
            'start_date' => now()->toDateString(),
        ]);

        foreach ($template->tasks as $templateTask) {
            Task::create([
                'workspace_id' => $template->workspace_id,
                'project_id' => $project->id,
                'title' => $templateTask->title,
                'description' => $templateTask->description,
                'section' => $templateTask->section,
                'status' => 'todo',
                'priority' => $templateTask->priority,
                'reporter_id' => $request->user()->id,
                'due_date' => $templateTask->offset_days === null
                    ? null
                    : now()->addDays($templateTask->offset_days)->toDateString(),
                'position' => $templateTask->position,
            ]);
        }

        return redirect()
            ->route('projects.show', $project)
            ->with('success', 'Project created from template.');
    }

    private function uniqueProjectSlug(string $name, int $workspaceId): string
    {
        $base = Str::slug($name) ?: 'project';
        $slug = $base;
        $counter = 2;

        while (Project::query()->where('workspace_id', $workspaceId)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
