<?php

use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AreaController;
use App\Http\Controllers\BlockerController;
use App\Http\Controllers\CustomFieldController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DecisionController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\PlannerController;
use App\Http\Controllers\PrioritizationReviewController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectBoardController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectMemberController;
use App\Http\Controllers\ProjectTimelineController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RiskController;
use App\Http\Controllers\Settings\AiSettingsController;
use App\Http\Controllers\Settings\AutomationSettingsController;
use App\Http\Controllers\Settings\IntegrationSettingsController;
use App\Http\Controllers\Settings\WorkspaceSettingsController;
use App\Http\Controllers\TaskAttachmentController;
use App\Http\Controllers\TaskCommentController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskSubtaskController;
use App\Http\Controllers\TaskReviewController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\SlackWebhookController;
use App\Http\Controllers\SpiritualController;
use App\Http\Controllers\TodayController;
use App\Http\Controllers\WaitingItemController;
use App\Http\Controllers\WorkloadController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', DashboardController::class)->middleware(['auth', 'verified'])->name('dashboard');

Route::post('/webhooks/slack/events', SlackWebhookController::class)->name('webhooks.slack.events');

Route::middleware('auth')->group(function () {
    Route::get('/today', [TodayController::class, 'index'])->name('today.index');
    Route::patch('/today/tasks/{task}/today', [TodayController::class, 'today'])->name('today.tasks.today');
    Route::patch('/today/tasks/{task}/tomorrow', [TodayController::class, 'tomorrow'])->name('today.tasks.tomorrow');
    Route::patch('/today/tasks/{task}/snooze', [TodayController::class, 'snooze'])->name('today.tasks.snooze');
    Route::get('/areas', [AreaController::class, 'index'])->name('areas.index');
    Route::get('/areas/{area}', [AreaController::class, 'show'])->name('areas.show');
    Route::get('/portfolios', [PortfolioController::class, 'index'])->name('portfolios.index');
    Route::get('/portfolios/create', [PortfolioController::class, 'create'])->name('portfolios.create');
    Route::post('/portfolios', [PortfolioController::class, 'store'])->name('portfolios.store');
    Route::get('/portfolios/{portfolio}', [PortfolioController::class, 'show'])->name('portfolios.show');
    Route::get('/portfolios/{portfolio}/edit', [PortfolioController::class, 'edit'])->name('portfolios.edit');
    Route::patch('/portfolios/{portfolio}', [PortfolioController::class, 'update'])->name('portfolios.update');
    Route::patch('/portfolios/{portfolio}/archive', [PortfolioController::class, 'archive'])->name('portfolios.archive');
    Route::patch('/portfolios/{portfolio}/restore', [PortfolioController::class, 'restore'])->name('portfolios.restore');
    Route::post('/portfolios/{portfolio}/projects', [PortfolioController::class, 'addProject'])->name('portfolios.projects.store');
    Route::delete('/portfolios/{portfolio}/projects/{project}', [PortfolioController::class, 'removeProject'])->name('portfolios.projects.destroy');
    Route::get('/goals', [GoalController::class, 'index'])->name('goals.index');
    Route::get('/goals/create', [GoalController::class, 'create'])->name('goals.create');
    Route::post('/goals', [GoalController::class, 'store'])->name('goals.store');
    Route::get('/goals/{goal}', [GoalController::class, 'show'])->name('goals.show');
    Route::get('/goals/{goal}/edit', [GoalController::class, 'edit'])->name('goals.edit');
    Route::patch('/goals/{goal}', [GoalController::class, 'update'])->name('goals.update');
    Route::patch('/goals/{goal}/archive', [GoalController::class, 'archive'])->name('goals.archive');
    Route::patch('/goals/{goal}/restore', [GoalController::class, 'restore'])->name('goals.restore');
    Route::post('/goals/{goal}/key-results', [GoalController::class, 'storeKeyResult'])->name('goals.key-results.store');
    Route::patch('/goals/{goal}/key-results/{keyResult}', [GoalController::class, 'updateKeyResult'])->name('goals.key-results.update');
    Route::get('/spiritual', [SpiritualController::class, 'index'])->name('spiritual.index');
    Route::post('/spiritual/readings/toggle', [SpiritualController::class, 'toggleReading'])->name('spiritual.readings.toggle');
    Route::post('/spiritual/journal', [SpiritualController::class, 'storeJournal'])->name('spiritual.journal.store');
    Route::post('/spiritual/notes', [SpiritualController::class, 'storeNote'])->name('spiritual.notes.store');
    Route::resource('notes', NoteController::class)->except(['edit']);
    Route::get('/planner', [PlannerController::class, 'index'])->name('planner.index');
    Route::patch('/planner/tasks/{task}/schedule', [PlannerController::class, 'schedule'])->name('planner.tasks.schedule');
    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
    Route::get('/templates', [TemplateController::class, 'index'])->name('templates.index');
    Route::post('/templates', [TemplateController::class, 'store'])->name('templates.store');
    Route::post('/templates/{template}/create-project', [TemplateController::class, 'createProject'])->name('templates.create-project');
    Route::get('/admin/settings/custom-fields', [CustomFieldController::class, 'index'])->name('admin.custom-fields.index');
    Route::post('/admin/settings/custom-fields', [CustomFieldController::class, 'store'])->name('admin.custom-fields.store');
    Route::get('/settings/ai', [AiSettingsController::class, 'edit'])->name('settings.ai.edit');
    Route::patch('/settings/ai', [AiSettingsController::class, 'update'])->name('settings.ai.update');
    Route::get('/settings/workspace', [WorkspaceSettingsController::class, 'edit'])->name('settings.workspace.edit');
    Route::patch('/settings/workspace', [WorkspaceSettingsController::class, 'update'])->name('settings.workspace.update');
    Route::post('/settings/workspace/members', [WorkspaceSettingsController::class, 'addMember'])->name('settings.workspace.members.store');
    Route::patch('/settings/workspace/members/{user}', [WorkspaceSettingsController::class, 'updateMember'])->name('settings.workspace.members.update');
    Route::delete('/settings/workspace/members/{user}', [WorkspaceSettingsController::class, 'removeMember'])->name('settings.workspace.members.destroy');
    Route::get('/settings/automations', [AutomationSettingsController::class, 'index'])->name('settings.automations.index');
    Route::post('/settings/automations', [AutomationSettingsController::class, 'store'])->name('settings.automations.store');
    Route::patch('/settings/automations/{automationRule}', [AutomationSettingsController::class, 'update'])->name('settings.automations.update');
    Route::patch('/settings/automations/{automationRule}/toggle', [AutomationSettingsController::class, 'toggle'])->name('settings.automations.toggle');
    Route::patch('/settings/automations/{automationRule}/archive', [AutomationSettingsController::class, 'archive'])->name('settings.automations.archive');
    Route::get('/settings/integrations', [IntegrationSettingsController::class, 'index'])->name('settings.integrations.index');
    Route::get('/settings/integrations/google/connect', [IntegrationSettingsController::class, 'connect'])->name('settings.integrations.google.connect');
    Route::get('/settings/integrations/google/callback', [IntegrationSettingsController::class, 'callback'])->name('settings.integrations.google.callback');
    Route::patch('/settings/integrations/google/{calendarConnection}/disconnect', [IntegrationSettingsController::class, 'disconnect'])->name('settings.integrations.google.disconnect');
    Route::post('/settings/integrations/google/sync', [IntegrationSettingsController::class, 'sync'])->name('settings.integrations.google.sync');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::patch('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/workload', [WorkloadController::class, 'index'])->name('workload.index');
    Route::get('/task-review', [TaskReviewController::class, 'index'])->name('task-review.index');
    Route::get('/prioritization-review', [PrioritizationReviewController::class, 'index'])->name('prioritization-review.index');
    Route::patch('/prioritization-review/apply', [PrioritizationReviewController::class, 'apply'])->name('prioritization-review.apply');

    Route::get('/waiting', [WaitingItemController::class, 'index'])->name('waiting.index');
    Route::post('/waiting', [WaitingItemController::class, 'store'])->name('waiting.store');
    Route::patch('/waiting/{waitingItem}', [WaitingItemController::class, 'update'])->name('waiting.update');
    Route::patch('/waiting/{waitingItem}/close', [WaitingItemController::class, 'close'])->name('waiting.close');
    Route::get('/decisions', [DecisionController::class, 'index'])->name('decisions.index');
    Route::post('/decisions', [DecisionController::class, 'store'])->name('decisions.store');
    Route::patch('/decisions/{decision}', [DecisionController::class, 'update'])->name('decisions.update');
    Route::patch('/decisions/{decision}/close', [DecisionController::class, 'close'])->name('decisions.close');
    Route::get('/blockers', [BlockerController::class, 'index'])->name('blockers.index');
    Route::post('/blockers', [BlockerController::class, 'store'])->name('blockers.store');
    Route::patch('/blockers/{blocker}', [BlockerController::class, 'update'])->name('blockers.update');
    Route::patch('/blockers/{blocker}/resolve', [BlockerController::class, 'close'])->name('blockers.close');
    Route::get('/risks', [RiskController::class, 'index'])->name('risks.index');
    Route::post('/risks', [RiskController::class, 'store'])->name('risks.store');
    Route::patch('/risks/{risk}', [RiskController::class, 'update'])->name('risks.update');
    Route::patch('/risks/{risk}/close', [RiskController::class, 'close'])->name('risks.close');
    Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
    Route::post('/approvals', [ApprovalController::class, 'store'])->name('approvals.store');
    Route::patch('/approvals/{approval}', [ApprovalController::class, 'update'])->name('approvals.update');
    Route::patch('/approvals/{approval}/approve', [ApprovalController::class, 'close'])->name('approvals.close');
    Route::patch('/approvals/{approval}/reject', [ApprovalController::class, 'reject'])->name('approvals.reject');

    Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::get('/tasks/create', [TaskController::class, 'create'])->name('tasks.create');
    Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::get('/tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
    Route::get('/tasks/{task}/edit', [TaskController::class, 'edit'])->name('tasks.edit');
    Route::patch('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::patch('/tasks/{task}/status', [TaskController::class, 'status'])->name('tasks.status');
    Route::patch('/tasks/{task}/complete', [TaskController::class, 'complete'])->name('tasks.complete');
    Route::patch('/tasks/{task}/archive', [TaskController::class, 'archive'])->name('tasks.archive');
    Route::patch('/tasks/{task}/restore', [TaskController::class, 'restore'])->name('tasks.restore');
    Route::post('/tasks/{task}/subtasks', [TaskSubtaskController::class, 'store'])->name('tasks.subtasks.store');
    Route::patch('/tasks/{task}/subtasks/{subtask}/status', [TaskSubtaskController::class, 'status'])->name('tasks.subtasks.status');
    Route::patch('/tasks/{task}/custom-fields', [CustomFieldController::class, 'updateTaskValues'])->name('tasks.custom-fields.update');
    Route::post('/tasks/{task}/comments', [TaskCommentController::class, 'store'])->name('tasks.comments.store');
    Route::post('/tasks/{task}/attachments', [TaskAttachmentController::class, 'store'])->name('tasks.attachments.store');
    Route::patch('/task-comments/{comment}', [TaskCommentController::class, 'update'])->name('task-comments.update');
    Route::delete('/task-comments/{comment}', [TaskCommentController::class, 'destroy'])->name('task-comments.destroy');
    Route::get('/task-attachments/{attachment}/download', [TaskAttachmentController::class, 'download'])->name('task-attachments.download');
    Route::delete('/task-attachments/{attachment}', [TaskAttachmentController::class, 'destroy'])->name('task-attachments.destroy');

    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::get('/projects/{project}/board', ProjectBoardController::class)->name('projects.board');
    Route::get('/projects/{project}/timeline', ProjectTimelineController::class)->name('projects.timeline');
    Route::get('/projects/{project}/edit', [ProjectController::class, 'edit'])->name('projects.edit');
    Route::patch('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::patch('/projects/{project}/archive', [ProjectController::class, 'archive'])->name('projects.archive');
    Route::patch('/projects/{project}/restore', [ProjectController::class, 'restore'])->name('projects.restore');
    Route::post('/projects/{project}/members', [ProjectMemberController::class, 'store'])->name('projects.members.store');
    Route::delete('/projects/{project}/members/{user}', [ProjectMemberController::class, 'destroy'])->name('projects.members.destroy');
    Route::get('/projects/{project}/tasks/create', [TaskController::class, 'create'])->name('projects.tasks.create');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
