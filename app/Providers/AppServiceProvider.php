<?php

namespace App\Providers;

use App\Models\CustomField;
use App\Models\Label;
use App\Models\Note;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\Workspace;
use App\Policies\CustomFieldPolicy;
use App\Policies\LabelPolicy;
use App\Policies\NotePolicy;
use App\Policies\NotificationPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\ProjectTemplatePolicy;
use App\Policies\TaskAttachmentPolicy;
use App\Policies\TaskPolicy;
use App\Policies\WorkspacePolicy;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(CustomField::class, CustomFieldPolicy::class);
        Gate::policy(Label::class, LabelPolicy::class);
        Gate::policy(Note::class, NotePolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(ProjectTemplate::class, ProjectTemplatePolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(TaskAttachment::class, TaskAttachmentPolicy::class);
        Gate::policy(Workspace::class, WorkspacePolicy::class);
        Gate::policy(DatabaseNotification::class, NotificationPolicy::class);

        Vite::prefetch(concurrency: 3);
    }
}
