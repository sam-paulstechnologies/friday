<?php

namespace App\Http\Controllers;

use App\Models\Approval;
use App\Models\Area;
use App\Models\Blocker;
use App\Models\Decision;
use App\Models\Portfolio;
use App\Models\Risk;
use App\Models\Task;
use App\Models\WaitingItem;
use App\Services\DailyReview\DailyReviewService;
use App\Services\Spiritual\SpiritualReadingSummaryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        DailyReviewService $dailyReviewService,
        SpiritualReadingSummaryService $spiritualReadingSummaryService
    ): Response
    {
        $user = $request->user();
        $workspaceIds = $user->accessibleWorkspaceIds();
        $groups = $dailyReviewService->collectTodayTasks($user);
        $focus = $dailyReviewService->selectTopFocusItems($groups);

        $commandTasks = Task::query()
            ->with(['area:id,name', 'portfolio:id,name', 'project:id,name'])
            ->where(function ($query) use ($user): void {
                $query->where('assignee_id', $user->id)->orWhere('reporter_id', $user->id);
            })
            ->active()
            ->whereIn('task_type', ['waiting_for', 'decision', 'blocker', 'risk', 'approval'])
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->get()
            ->groupBy('task_type');

        $completedTasks = Task::query()
            ->with(['area:id,name', 'portfolio:id,name', 'project:id,name', 'workspace:id,name'])
            ->where(function ($query) use ($user): void {
                $query->where('assignee_id', $user->id)->orWhere('reporter_id', $user->id);
            })
            ->where('status', 'completed')
            ->orderByRaw('completed_at is null')
            ->orderByDesc('completed_at')
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get();

        return Inertia::render('Dashboard', [
            'date' => now()->format('l, F j, Y'),
            'summary' => [
                'overdue' => $groups->get('overdue')->count(),
                'missed_yesterday' => $groups->get('missed_yesterday')->count(),
                'due_today' => $groups->get('due_today')->count(),
                'scheduled_today' => $groups->get('scheduled_today')->count(),
                'upcoming' => $groups->get('upcoming')->count(),
                'completed_today' => $groups->get('completed_today')->count(),
                'waiting_for' => WaitingItem::where('user_id', $user->id)->where('status', 'open')->count() + $commandTasks->get('waiting_for', collect())->count(),
                'blockers' => Blocker::where('user_id', $user->id)->where('status', 'open')->count() + $commandTasks->get('blocker', collect())->count(),
                'decisions' => Decision::where('user_id', $user->id)->where('status', 'pending')->count() + $commandTasks->get('decision', collect())->count(),
                'risks' => Risk::where('user_id', $user->id)->where('status', 'open')->count() + $commandTasks->get('risk', collect())->count(),
                'approvals' => Approval::where('user_id', $user->id)->where('status', 'pending')->count() + $commandTasks->get('approval', collect())->count(),
            ],
            'focus' => $focus->map(fn (Task $task) => $this->taskResource($task))->values(),
            'overdue' => $groups->get('overdue')->map(fn (Task $task) => $this->taskResource($task))->values(),
            'dueToday' => $groups->get('due_today')->map(fn (Task $task) => $this->taskResource($task))->values(),
            'scheduledToday' => $groups->get('scheduled_today')->map(fn (Task $task) => $this->taskResource($task))->values(),
            'missedYesterday' => $groups->get('missed_yesterday')->map(fn (Task $task) => $this->taskResource($task))->values(),
            'weeklyFocus' => $groups->get('upcoming')->map(fn (Task $task) => $this->taskResource($task))->values(),
            'completedToday' => $groups->get('completed_today')->map(fn (Task $task) => $this->taskResource($task))->values(),
            'completedTasks' => $completedTasks->map(fn (Task $task) => $this->taskResource($task))->values(),
            'spiritualReading' => $spiritualReadingSummaryService->forUser($user),
            'commandCenter' => [
                'waiting' => $this->commandObjects(WaitingItem::class, $user->id, 'open', $commandTasks->get('waiting_for', collect())),
                'decisions' => $this->commandObjects(Decision::class, $user->id, 'pending', $commandTasks->get('decision', collect())),
                'blockers' => $this->commandObjects(Blocker::class, $user->id, 'open', $commandTasks->get('blocker', collect())),
                'risks' => $this->commandObjects(Risk::class, $user->id, 'open', $commandTasks->get('risk', collect())),
                'approvals' => $this->commandObjects(Approval::class, $user->id, 'pending', $commandTasks->get('approval', collect())),
            ],
            'areaHealth' => Area::query()->orderBy('position')->get()->map(fn (Area $area) => [
                'id' => $area->id,
                'name' => $area->name,
                'color' => $area->color,
                'open_tasks' => $area->tasks()->whereIn('workspace_id', $workspaceIds)->active()->count(),
                'overdue_tasks' => $area->tasks()->whereIn('workspace_id', $workspaceIds)->active()->overdue()->count(),
                'projects' => $area->projects()->whereIn('workspace_id', $workspaceIds)->active()->count(),
            ]),
            'portfolioProgress' => Portfolio::query()
                ->when(
                    $workspaceIds !== [],
                    fn ($query) => $query->whereIn('workspace_id', $workspaceIds),
                    fn ($query) => $query->whereRaw('1 = 0'),
                )
                ->whereIn('name', ['Publicis Digitas', 'SayaraForce', 'ChurchForce', 'The Pauls Technologies', 'UAE Realtor Agents App'])
                ->with('area:id,name')
                ->orderBy('position')
                ->get()
                ->map(fn (Portfolio $portfolio) => [
                    'id' => $portfolio->id,
                    'name' => $portfolio->name,
                    'area' => $portfolio->area?->name,
                    'open_tasks' => $portfolio->tasks()->active()->count(),
                    'projects' => $portfolio->projects()->active()->count(),
                ]),
        ]);
    }

    private function taskResource(Task $task): array
    {
        $task->loadMissing(['area:id,name', 'portfolio:id,name', 'project:id,name', 'workspace:id,name']);

        return [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'priority' => $task->priority,
            'task_type' => $task->task_type,
            'due_date' => $task->due_date?->toDateString(),
            'completed_at' => $task->completed_at?->toDateTimeString(),
            'area' => $task->area?->only(['id', 'name']),
            'portfolio' => $task->portfolio?->only(['id', 'name']),
            'project' => $task->project?->only(['id', 'name']),
            'workspace' => $task->workspace?->only(['id', 'name']),
        ];
    }

    private function commandObjects(string $modelClass, int $userId, string $openStatus, $typedTasks): array
    {
        $objects = $modelClass::query()
            ->with(['area:id,name', 'portfolio:id,name', 'project:id,name'])
            ->where('user_id', $userId)
            ->where('status', $openStatus)
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'kind' => 'object',
                'title' => $item->title,
                'status' => $item->status,
                'area' => $item->area?->name,
                'portfolio' => $item->portfolio?->name,
                'project' => $item->project?->name,
            ]);

        $tasks = $typedTasks->take(5)->map(fn (Task $task) => [
            'id' => $task->id,
            'kind' => 'task',
            'title' => $task->title,
            'status' => $task->status,
            'area' => $task->area?->name,
            'portfolio' => $task->portfolio?->name,
            'project' => $task->project?->name,
        ]);

        return $objects->toBase()->merge($tasks)->values()->all();
    }
}
