<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Services\Inbox\InboxConversionException;
use App\Services\Inbox\InboxService;
use App\Services\Tasks\InvalidTaskTransitionException;
use App\Services\Tasks\TaskTransitionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InboxController extends Controller
{
    public function __construct(private readonly InboxService $inbox) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Inbox/Index', [
            'inbox' => $this->inbox->items($request->user()),
            'destinations' => $this->destinations(),
        ]);
    }

    public function show(Request $request, string $source, int $id): Response
    {
        $this->assertSource($source);

        return Inertia::render('Inbox/Show', [
            'item' => $this->inbox->show($request->user(), $source, $id),
            'destinations' => $this->destinations(),
        ]);
    }

    public function convert(Request $request, string $source, int $id): RedirectResponse
    {
        $this->assertSource($source);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'priority' => ['sometimes', 'nullable', Rule::in(Task::PRIORITIES)],
            'task_type' => ['sometimes', 'nullable', Rule::in(Task::TYPES)],
            'project_id' => ['sometimes', 'nullable', 'integer'],
            'destination' => ['sometimes', Rule::in(array_keys(TaskTransitionService::MOVES))],
        ]);

        try {
            $result = $this->inbox->convert($request->user(), $source, $id, $data);
        } catch (InboxConversionException|InvalidTaskTransitionException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('tasks.show', $result['task'])
            ->with('success', $result['created']
                ? 'Capture converted into a task.'
                : 'That capture was already converted; opening the existing task.');
    }

    public function move(Request $request, string $source, int $id): RedirectResponse
    {
        $this->assertSource($source);

        $data = $request->validate([
            'destination' => ['required', Rule::in(array_keys(TaskTransitionService::MOVES))],
        ]);

        try {
            $this->inbox->move($request->user(), $source, $id, $data['destination']);
        } catch (InboxConversionException|InvalidTaskTransitionException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Moved to '.$this->destinations()[$data['destination']].'.');
    }

    public function dismiss(Request $request, string $source, int $id): RedirectResponse
    {
        $this->assertSource($source);

        $this->inbox->dismiss($request->user(), $source, $id);

        return back()->with('success', 'Capture dismissed. The original wording is kept.');
    }

    /** @return array<string, string> */
    private function destinations(): array
    {
        return [
            TaskTransitionService::MOVE_TODAY => 'Today',
            TaskTransitionService::MOVE_THIS_WEEK => 'This week',
            TaskTransitionService::MOVE_THIS_MONTH => 'This month',
            TaskTransitionService::MOVE_LATER => 'Later',
            TaskTransitionService::MOVE_WAITING => 'Waiting',
            TaskTransitionService::MOVE_DELEGATED => 'Delegated',
            TaskTransitionService::MOVE_TASKS => 'Tasks',
        ];
    }

    private function assertSource(string $source): void
    {
        abort_unless(in_array($source, InboxService::SOURCES, true), 404);
    }
}
