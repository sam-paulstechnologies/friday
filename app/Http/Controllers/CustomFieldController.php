<?php

namespace App\Http\Controllers;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Task;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomFieldController extends Controller
{
    public function index(Request $request): Response
    {
        $workspaceIds = $request->user()->accessibleWorkspaceIds();

        return Inertia::render('Admin/CustomFields/Index', [
            'workspaces' => Workspace::query()->select(['id', 'name'])->whereIn('id', $workspaceIds)->orderBy('name')->get(),
            'customFields' => CustomField::query()
                ->with('workspace:id,name')
                ->whereIn('workspace_id', $workspaceIds)
                ->latest()
                ->get()
                ->map(fn (CustomField $field) => $this->fieldResource($field)),
            'fieldTypes' => CustomField::FIELD_TYPES,
            'appliesTo' => CustomField::APPLIES_TO,
        ]);
    }

    public function store(Request $request)
    {
        $workspaceIds = $request->user()->accessibleWorkspaceIds();

        $data = $request->validate([
            'workspace_id' => ['required', 'integer', Rule::exists('workspaces', 'id')->where(fn ($query) => $query->whereIn('id', $workspaceIds))],
            'name' => ['required', 'string', 'max:255'],
            'field_type' => ['required', Rule::in(CustomField::FIELD_TYPES)],
            'applies_to' => ['required', Rule::in(CustomField::APPLIES_TO)],
            'options' => ['nullable', 'string', 'max:5000'],
        ]);

        Gate::authorize('create', [CustomField::class, Workspace::findOrFail($data['workspace_id'])]);

        CustomField::create([
            'workspace_id' => $data['workspace_id'],
            'name' => $data['name'],
            'key' => $this->uniqueKey($data['name'], (int) $data['workspace_id']),
            'field_type' => $data['field_type'],
            'applies_to' => $data['applies_to'],
            'options' => $this->parseOptions($data['options'] ?? null),
        ]);

        return back()->with('success', 'Custom field created.');
    }

    public function updateTaskValues(Request $request, Task $task)
    {
        Gate::authorize('update', $task);

        $fields = CustomField::query()
            ->where('workspace_id', $task->workspace_id)
            ->whereIn('applies_to', ['task', 'both'])
            ->get();

        $rules = $fields->mapWithKeys(fn (CustomField $field) => [
            "values.{$field->id}" => ['nullable', 'string', 'max:5000'],
        ])->all();

        $data = $request->validate($rules);

        foreach (($data['values'] ?? []) as $fieldId => $value) {
            if (! $fields->contains('id', (int) $fieldId)) {
                continue;
            }

            CustomFieldValue::updateOrCreate(
                [
                    'custom_field_id' => $fieldId,
                    'entity_type' => Task::class,
                    'entity_id' => $task->id,
                ],
                ['value' => $value],
            );
        }

        return back()->with('success', 'Custom fields updated.');
    }

    private function parseOptions(?string $options): ?array
    {
        $items = collect(explode("\n", (string) $options))
            ->map(fn (string $item) => trim($item))
            ->filter()
            ->values()
            ->all();

        return $items ?: null;
    }

    private function uniqueKey(string $name, int $workspaceId): string
    {
        $base = Str::slug($name, '_') ?: 'field';
        $key = $base;
        $counter = 2;

        while (CustomField::query()->where('workspace_id', $workspaceId)->where('key', $key)->exists()) {
            $key = "{$base}_{$counter}";
            $counter++;
        }

        return $key;
    }

    private function fieldResource(CustomField $field): array
    {
        return [
            'id' => $field->id,
            'workspace_id' => $field->workspace_id,
            'name' => $field->name,
            'key' => $field->key,
            'field_type' => $field->field_type,
            'options' => $field->options ?? [],
            'applies_to' => $field->applies_to,
            'workspace' => $field->workspace ? [
                'id' => $field->workspace->id,
                'name' => $field->workspace->name,
            ] : null,
        ];
    }
}
