<?php

namespace App\Http\Controllers;

use App\Services\Authorization\CommandCenterAccessService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

abstract class CommandCenterController extends Controller
{
    public function __construct(protected readonly CommandCenterAccessService $access) {}

    abstract protected function modelClass(): string;

    abstract protected function page(): string;

    abstract protected function openStatus(): string;

    abstract protected function closedStatus(): string;

    abstract protected function closedTimestampColumn(): string;

    protected function extraValidation(): array
    {
        return [];
    }

    protected function extraDefaults(Request $request): array
    {
        return [];
    }

    public function index(Request $request): Response
    {
        $modelClass = $this->modelClass();

        $relations = ['area:id,name', 'portfolio:id,name,workspace_id', 'project:id,name,workspace_id,owner_id'];

        if (method_exists($modelClass, 'task')) {
            $relations[] = 'task:id,title,workspace_id,assignee_id,reporter_id';
        }

        $items = $modelClass::query()
            ->with($relations)
            ->where('user_id', $request->user()->id)
            ->orderByRaw('status = ? desc', [$this->openStatus()])
            ->latest()
            ->get()
            ->map(fn (Model $item) => $this->itemResource($this->access->redactInaccessibleRelations($request->user(), $item)));

        return Inertia::render($this->page(), [
            'items' => $items,
            'openStatus' => $this->openStatus(),
            'options' => $this->access->options($request->user()),
        ]);
    }

    public function store(Request $request)
    {
        $this->access->authorizeWrite($request->user());
        $data = $this->validatedData($request);
        $data['user_id'] = $request->user()->id;

        $this->modelClass()::create([
            ...$data,
            ...$this->extraDefaults($request),
        ]);

        return back()->with('success', 'Item created.');
    }

    protected function updateItem(Request $request, Model $item)
    {
        $this->access->authorizeWrite($request->user(), $item);

        $data = $this->validatedData($request);
        $this->access->validateRelations($request->user(), array_merge($item->only(['area_id', 'portfolio_id', 'project_id', 'task_id']), $data));
        $item->update($data);

        return back()->with('success', 'Item updated.');
    }

    protected function closeItem(Request $request, Model $item)
    {
        $this->access->authorizeWrite($request->user(), $item);

        $this->access->validateRelations($request->user(), $item->only(['area_id', 'portfolio_id', 'project_id', 'task_id']));
        $request->validate(['status' => ['sometimes', Rule::in([$this->closedStatus()])]]);
        $item->update([
            'status' => $this->closedStatus(),
            $this->closedTimestampColumn() => now(),
        ]);

        return back()->with('success', 'Item closed.');
    }

    protected function validatedData(Request $request): array
    {
        $data = $request->validate([
            'area_id' => ['nullable', 'integer', Rule::exists('areas', 'id')],
            'portfolio_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'task_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            ...$this->extraValidation(),
        ]);
        $this->access->validateRelations($request->user(), $data);

        return $data;
    }

    protected function itemResource(Model $item): array
    {
        return [
            'id' => $item->id,
            'title' => $item->title,
            'description' => $item->description,
            'status' => $item->status,
            'created_at' => $item->created_at?->toDateTimeString(),
            'area_id' => $item->area_id,
            'portfolio_id' => $item->portfolio_id,
            'project_id' => $item->project_id,
            'task_id' => $item->task_id ?? null,
            'area' => $item->area?->only(['id', 'name']),
            'portfolio' => $item->portfolio?->only(['id', 'name']),
            'project' => $item->project?->only(['id', 'name']),
            'task' => $item->task?->only(['id', 'title']),
            'waiting_on' => $item->waiting_on ?? null,
            'follow_up_date' => $item->follow_up_date?->toDateString(),
            'decision_due_date' => $item->decision_due_date?->toDateString(),
            'decision' => $item->decision ?? null,
            'severity' => $item->severity ?? null,
            'impact' => $item->impact ?? null,
            'probability' => $item->probability ?? null,
            'mitigation' => $item->mitigation ?? null,
            'requested_by' => $item->requested_by ?? null,
        ];
    }
}
