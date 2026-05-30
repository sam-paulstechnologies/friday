<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\BibleReadingPlanDay;
use App\Models\Note;
use App\Models\Portfolio;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class NoteController extends Controller
{
    public function index(Request $request): Response
    {
        $notes = Note::query()
            ->with(['area:id,name', 'portfolio:id,name', 'project:id,name', 'task:id,title'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('pinned')
            ->latest()
            ->get()
            ->map(fn (Note $note) => $this->resource($note));

        return Inertia::render('Notes/Index', [
            'notes' => $notes,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Notes/Create', $this->options(request()));
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Note::class);

        $note = Note::create([
            ...$this->validated($request),
            'user_id' => $request->user()->id,
        ]);

        return redirect()->route('notes.show', $note)->with('success', 'Note saved.');
    }

    public function show(Request $request, Note $note): Response
    {
        Gate::authorize('view', $note);

        $note->load(['area:id,name', 'portfolio:id,name', 'project:id,name', 'task:id,title']);

        return Inertia::render('Notes/Show', [
            'note' => $this->resource($note),
            ...$this->options($request),
        ]);
    }

    public function update(Request $request, Note $note): RedirectResponse
    {
        Gate::authorize('update', $note);

        $note->update($this->validated($request));

        return redirect()->route('notes.show', $note)->with('success', 'Note updated.');
    }

    public function destroy(Request $request, Note $note): RedirectResponse
    {
        Gate::authorize('delete', $note);

        $note->delete();

        return redirect()->route('notes.index')->with('success', 'Note deleted.');
    }

    private function validated(Request $request): array
    {
        $workspaceIds = $request->user()->accessibleWorkspaceIds();

        $data = $request->validate([
            'workspace_id' => ['nullable', 'integer', Rule::exists('workspaces', 'id')->where(fn ($query) => $query->whereIn('id', $workspaceIds))],
            'area_id' => ['nullable', 'integer', Rule::exists('areas', 'id')],
            'portfolio_id' => ['nullable', 'integer', Rule::exists('portfolios', 'id')->where(fn ($query) => $query->whereIn('workspace_id', $workspaceIds))],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where(fn ($query) => $query->whereIn('workspace_id', $workspaceIds))],
            'task_id' => ['nullable', 'integer', Rule::exists('tasks', 'id')->where(fn ($query) => $query->whereIn('workspace_id', $workspaceIds))],
            'spiritual_reading_day_id' => ['nullable', 'integer', Rule::exists('bible_reading_plan_days', 'id')],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'canvas_data' => ['nullable', 'string'],
            'canvas_preview_path' => ['nullable', 'string'],
            'note_type' => ['required', Rule::in(Note::TYPES)],
            'tags' => ['nullable', 'string', 'max:500'],
            'pinned' => ['nullable', 'boolean'],
        ]);

        $data['tags'] = $this->tags($data['tags'] ?? null);
        $data['pinned'] = (bool) ($data['pinned'] ?? false);

        return $data;
    }

    private function options(Request $request): array
    {
        $workspaceIds = $request->user()->accessibleWorkspaceIds();

        return [
            'workspaces' => Workspace::query()->select(['id', 'name'])->whereIn('id', $workspaceIds)->orderBy('name')->get(),
            'areas' => Area::query()->select(['id', 'name'])->orderBy('position')->get(),
            'portfolios' => Portfolio::query()->select(['id', 'area_id', 'name'])->whereIn('workspace_id', $workspaceIds)->orderBy('name')->get(),
            'projects' => Project::query()->select(['id', 'workspace_id', 'area_id', 'portfolio_id', 'name'])->whereIn('workspace_id', $workspaceIds)->orderBy('name')->get(),
            'tasks' => Task::query()->select(['id', 'workspace_id', 'area_id', 'portfolio_id', 'project_id', 'title'])->whereIn('workspace_id', $workspaceIds)->latest()->limit(150)->get(),
            'spiritualDays' => BibleReadingPlanDay::query()->select(['id', 'day_number', 'reading_date'])->orderBy('day_number')->limit(90)->get(),
            'noteTypes' => Note::TYPES,
        ];
    }

    private function resource(Note $note): array
    {
        return [
            'id' => $note->id,
            'workspace_id' => $note->workspace_id,
            'area_id' => $note->area_id,
            'portfolio_id' => $note->portfolio_id,
            'project_id' => $note->project_id,
            'task_id' => $note->task_id,
            'spiritual_reading_day_id' => $note->spiritual_reading_day_id,
            'title' => $note->title,
            'content' => $note->content,
            'canvas_data' => $note->canvas_data,
            'canvas_preview_path' => $note->canvas_preview_path,
            'note_type' => $note->note_type,
            'tags' => $note->tags ?? [],
            'pinned' => $note->pinned,
            'updated_at' => $note->updated_at?->toDateTimeString(),
            'area' => $note->area?->only(['id', 'name']),
            'portfolio' => $note->portfolio?->only(['id', 'name']),
            'project' => $note->project?->only(['id', 'name']),
            'task' => $note->task?->only(['id', 'title']),
        ];
    }

    private function tags(?string $tags): ?array
    {
        if (! $tags) {
            return null;
        }

        return collect(explode(',', $tags))->map(fn ($tag) => trim($tag))->filter()->values()->all();
    }
}
