<?php

namespace App\Http\Controllers;

use App\Models\AiConversation;
use App\Models\Task;
use App\Services\Ai\AiAssistantActionService;
use App\Services\Ai\AiAssistantConfig;
use App\Services\Ai\AiAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AssistantController extends Controller
{
    public function index(Request $request, AiAssistantConfig $config): Response
    {
        return Inertia::render('Assistant/Index', [
            'assistant' => [
                'enabled' => $config->enabled(),
                'provider' => $config->provider(),
                'model' => $config->model(),
                'api_key_configured' => $config->apiKeyConfigured(),
                'recent_conversations' => $request->user()->aiConversations()
                    ->latest()
                    ->limit(8)
                    ->get(['id', 'title', 'created_at'])
                    ->map(fn (AiConversation $conversation) => [
                        'id' => $conversation->id,
                        'title' => $conversation->title,
                        'created_at' => $conversation->created_at?->toDateTimeString(),
                    ]),
            ],
        ]);
    }

    public function message(Request $request, AiAssistantService $assistant): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'integer'],
        ]);

        $conversation = null;
        if (! empty($data['conversation_id'])) {
            $conversation = $request->user()->aiConversations()->whereKey((int) $data['conversation_id'])->firstOrFail();
        }

        return response()->json($assistant->respond($request->user(), $data['message'], $conversation));
    }

    public function createTask(Request $request, AiAssistantActionService $actions): JsonResponse
    {
        $workspaceIds = collect($request->user()->accessibleWorkspaceIds())
            ->filter(fn (int $workspaceId) => $request->user()->canWriteWorkspace($workspaceId))
            ->values()
            ->all();

        $data = $request->validate([
            'workspace_id' => ['required', 'integer', Rule::exists('workspaces', 'id')->where(fn ($query) => $query->whereIn('id', $workspaceIds))],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where(fn ($query) => $query->whereIn('workspace_id', $workspaceIds))],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'priority' => ['nullable', Rule::in(Task::PRIORITIES)],
            'assignee_id' => ['nullable', 'integer', Rule::in($request->user()->workspaceUsersQuery()->pluck('id')->unique()->values()->all())],
            'due_date' => ['nullable', 'date'],
        ]);

        $action = $actions->createTask($request->user(), $data);
        $task = $action->target;

        return response()->json([
            'message' => 'Task created.',
            'action' => [
                'id' => $action->id,
                'status' => $action->status,
                'task_id' => $task?->id,
                'task_url' => $task ? route('tasks.show', $task, false) : null,
            ],
        ], 201);
    }
}
