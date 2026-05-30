<?php

namespace Database\Seeders;

use App\Models\Team;
use App\Models\Area;
use App\Models\Portfolio;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskComment;
use App\Models\User;
use App\Models\Workspace;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) && ! (bool) env('TASKFLOW_ALLOW_DEMO_SEEDING', false)) {
            $this->command?->warn('Demo seed data skipped outside local/testing. Set TASKFLOW_ALLOW_DEMO_SEEDING=true only for non-production demo environments.');

            return;
        }

        $defaultUser = User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => env('TASKFLOW_DEMO_PASSWORD', 'password'),
            ],
        );

        $workspace = Workspace::firstOrCreate(
            ['slug' => 'taskflow-workspace'],
            [
                'name' => 'Friday Workspace',
                'created_by' => $defaultUser->id,
            ],
        );

        $team = Team::firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'slug' => 'product-team',
            ],
            [
                'name' => 'Product Team',
                'description' => 'Default team for early Friday planning and setup.',
            ],
        );

        $areaDefinitions = [
            ['name' => 'Career', 'color' => '#2563eb', 'icon' => 'briefcase'],
            ['name' => 'Ventures', 'color' => '#7c3aed', 'icon' => 'rocket'],
            ['name' => 'Helping Others', 'color' => '#059669', 'icon' => 'hands'],
            ['name' => 'Personal Foundation', 'color' => '#ea580c', 'icon' => 'foundation'],
            ['name' => 'Finance & Assets', 'color' => '#0f766e', 'icon' => 'finance'],
            ['name' => 'CEO Command Center', 'color' => '#111827', 'icon' => 'command'],
        ];

        $areas = collect($areaDefinitions)->mapWithKeys(function (array $area, int $index): array {
            $model = Area::updateOrCreate(
                ['slug' => Str::slug($area['name'])],
                [
                    'name' => $area['name'],
                    'description' => "{$area['name']} operating area.",
                    'color' => $area['color'],
                    'icon' => $area['icon'],
                    'position' => $index + 1,
                    'is_active' => true,
                ],
            );

            return [$area['name'] => $model];
        });

        $portfolioGroups = [
            'Career' => ['Publicis Digitas', 'Stellantis GCC', 'Stellantis South Africa', 'Digitas Internal', 'Reporting & Automation', 'Career Growth'],
            'Ventures' => ['SayaraForce', 'ChurchForce', 'LifeBoard / JARVIS', 'Future SaaS Ideas'],
            'Helping Others' => ['The Pauls Technologies', 'UAE Realtor Agents App', 'Family / Other Requests'],
            'Personal Foundation' => ['Spirituality', 'Health & Fitness', 'Family Life', 'Learning', 'Personal Admin'],
            'Finance & Assets' => ['Monthly Budget', 'Loans', 'Net Worth', 'Dubai House Goal', 'Emergency Fund'],
            'CEO Command Center' => ['Daily Focus', 'Weekly Focus', 'Decisions', 'Waiting For', 'Risks', 'Approvals'],
        ];

        foreach ($portfolioGroups as $areaName => $portfolioNames) {
            $area = $areas->get($areaName);

            foreach ($portfolioNames as $index => $portfolioName) {
                Portfolio::updateOrCreate(
                    [
                        'area_id' => $area?->id,
                        'slug' => Str::slug($portfolioName),
                    ],
                    [
                        'workspace_id' => $workspace->id,
                        'name' => $portfolioName,
                        'description' => "{$portfolioName} portfolio.",
                        'color' => $area?->color,
                        'icon' => $area?->icon,
                        'status' => 'active',
                        'position' => $index + 1,
                    ],
                );
            }
        }

        $demoUsers = collect([
            [
                'name' => 'Maya Product',
                'email' => 'maya.product@example.com',
                'workspace_role' => 'admin',
                'team_role' => 'lead',
            ],
            [
                'name' => 'Noah Design',
                'email' => 'noah.design@example.com',
                'workspace_role' => 'member',
                'team_role' => 'member',
            ],
            [
                'name' => 'Ava Operations',
                'email' => 'ava.operations@example.com',
                'workspace_role' => 'member',
                'team_role' => 'member',
            ],
        ])->map(fn (array $demoUser) => [
            ...$demoUser,
            'model' => User::firstOrCreate(
                ['email' => $demoUser['email']],
                [
                    'name' => $demoUser['name'],
                    'password' => env('TASKFLOW_DEMO_PASSWORD', 'password'),
                ],
            ),
        ]);

        User::query()->each(function (User $user) use ($workspace, $team, $defaultUser): void {
            $workspace->users()->syncWithoutDetaching([
                $user->id => [
                    'role' => $user->is($defaultUser) ? 'owner' : 'member',
                    'joined_at' => now(),
                ],
            ]);

            $team->users()->syncWithoutDetaching([
                $user->id => [
                    'role' => $user->is($defaultUser) ? 'lead' : 'member',
                    'joined_at' => now(),
                ],
            ]);
        });

        $demoUsers->each(function (array $demoUser) use ($workspace, $team): void {
            $workspace->users()->syncWithoutDetaching([
                $demoUser['model']->id => [
                    'role' => $demoUser['workspace_role'],
                    'joined_at' => now(),
                ],
            ]);

            $team->users()->syncWithoutDetaching([
                $demoUser['model']->id => [
                    'role' => $demoUser['team_role'],
                    'joined_at' => now(),
                ],
            ]);
        });

        $usersByEmail = User::query()
            ->whereIn('email', [
                'test@example.com',
                'maya.product@example.com',
                'noah.design@example.com',
                'ava.operations@example.com',
            ])
            ->get()
            ->keyBy('email');

        $projects = [
            [
                'name' => 'Product Launch Plan',
                'slug' => 'product-launch-plan',
                'description' => 'Coordinate launch readiness, messaging, and cross-functional checkpoints.',
                'status' => 'active',
                'visibility' => 'workspace',
                'color' => '#2563eb',
            ],
            [
                'name' => 'Website Redesign',
                'slug' => 'website-redesign',
                'description' => 'Refresh the public website structure, content, and conversion paths.',
                'status' => 'on_hold',
                'visibility' => 'team',
                'color' => '#7c3aed',
            ],
            [
                'name' => 'Internal Operations',
                'slug' => 'internal-operations',
                'description' => 'Improve repeatable internal workflows and operational planning.',
                'status' => 'active',
                'visibility' => 'private',
                'color' => '#059669',
            ],
        ];

        foreach ($projects as $project) {
            Project::firstOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'slug' => $project['slug'],
                ],
                [
                    ...$project,
                    'workspace_id' => $workspace->id,
                    'team_id' => $team->id,
                    'owner_id' => $defaultUser->id,
                ],
            );
        }

        $seededProjects = Project::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('slug', ['product-launch-plan', 'website-redesign', 'internal-operations'])
            ->get()
            ->keyBy('slug');

        $tasks = [
            [
                'project_slug' => 'product-launch-plan',
                'title' => 'Finalize launch checklist',
                'description' => 'Confirm launch owners, dependencies, and final readiness checkpoints.',
                'status' => 'in_progress',
                'priority' => 'urgent',
                'section' => 'Launch readiness',
                'position' => 1,
                'assignee_email' => 'maya.product@example.com',
                'start_date' => now()->toDateString(),
                'due_date' => now()->addDays(3)->toDateString(),
            ],
            [
                'project_slug' => 'website-redesign',
                'title' => 'Prepare homepage content',
                'description' => 'Draft homepage messaging and align page sections with the redesign brief.',
                'status' => 'todo',
                'priority' => 'high',
                'section' => 'Content',
                'position' => 2,
                'assignee_email' => 'noah.design@example.com',
                'start_date' => now()->addDay()->toDateString(),
                'due_date' => now()->addDays(8)->toDateString(),
            ],
            [
                'project_slug' => 'internal-operations',
                'title' => 'Review internal process',
                'description' => 'Review the current operations workflow and identify repeatable improvements.',
                'status' => 'review',
                'priority' => 'medium',
                'section' => 'Operations',
                'position' => 3,
                'assignee_email' => 'ava.operations@example.com',
                'start_date' => now()->subDays(2)->toDateString(),
                'due_date' => now()->addDays(2)->toDateString(),
            ],
            [
                'project_slug' => 'website-redesign',
                'title' => 'Follow up with design team',
                'description' => 'Collect open design decisions and unblock the next content review.',
                'status' => 'blocked',
                'priority' => 'high',
                'section' => 'Design',
                'position' => 4,
                'assignee_email' => 'noah.design@example.com',
                'start_date' => now()->subDay()->toDateString(),
                'due_date' => now()->addDays(5)->toDateString(),
            ],
            [
                'project_slug' => 'product-launch-plan',
                'title' => 'Confirm project timeline',
                'description' => 'Validate launch milestones and make sure dates are realistic.',
                'status' => 'todo',
                'priority' => 'medium',
                'section' => 'Planning',
                'position' => 5,
                'assignee_email' => 'maya.product@example.com',
                'start_date' => now()->toDateString(),
                'due_date' => now()->addDays(6)->toDateString(),
            ],
        ];

        foreach ($tasks as $task) {
            $project = $seededProjects->get($task['project_slug']);

            if (! $project) {
                continue;
            }

            Task::updateOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'project_id' => $project->id,
                    'title' => $task['title'],
                ],
                [
                    'workspace_id' => $workspace->id,
                    'project_id' => $project->id,
                    'section' => $task['section'],
                    'description' => $task['description'],
                    'status' => $task['status'],
                    'priority' => $task['priority'],
                    'assignee_id' => $usersByEmail->get($task['assignee_email'])?->id ?? $defaultUser->id,
                    'reporter_id' => $defaultUser->id,
                    'start_date' => $task['start_date'],
                    'due_date' => $task['due_date'],
                    'position' => $task['position'],
                ],
            );
        }

        $seededTasks = Task::query()
            ->with(['assignee', 'reporter'])
            ->where('workspace_id', $workspace->id)
            ->whereIn('title', collect($tasks)->pluck('title'))
            ->get()
            ->keyBy('title');

        $comments = [
            'Finalize launch checklist' => [
                ['email' => 'maya.product@example.com', 'body' => 'I added the launch dependencies we still need to confirm.'],
                ['email' => 'test@example.com', 'body' => 'Good. Please keep the final readiness list focused on blockers only.'],
            ],
            'Prepare homepage content' => [
                ['email' => 'noah.design@example.com', 'body' => 'Hero copy is drafted. Waiting on final product screenshots.'],
                ['email' => 'maya.product@example.com', 'body' => 'Let us use the current screenshots if the new set is not ready by Friday.'],
            ],
            'Review internal process' => [
                ['email' => 'ava.operations@example.com', 'body' => 'I found three repeatable handoff steps we can simplify.'],
            ],
            'Follow up with design team' => [
                ['email' => 'noah.design@example.com', 'body' => 'Design review is blocked until the navigation direction is approved.'],
            ],
            'Confirm project timeline' => [
                ['email' => 'maya.product@example.com', 'body' => 'Timeline looks realistic if content sign-off lands this week.'],
            ],
        ];

        foreach ($comments as $taskTitle => $taskComments) {
            $task = $seededTasks->get($taskTitle);

            if (! $task) {
                continue;
            }

            foreach ($taskComments as $comment) {
                TaskComment::firstOrCreate(
                    [
                        'task_id' => $task->id,
                        'user_id' => $usersByEmail->get($comment['email'])?->id ?? $defaultUser->id,
                        'body' => $comment['body'],
                    ],
                );
            }
        }

        foreach ($seededTasks as $task) {
            TaskActivity::firstOrCreate(
                [
                    'task_id' => $task->id,
                    'action' => 'task_created',
                    'description' => 'Demo task was seeded.',
                ],
                [
                    'user_id' => $task->reporter_id,
                ],
            );

            TaskActivity::firstOrCreate(
                [
                    'task_id' => $task->id,
                    'action' => 'status_changed',
                    'old_value' => 'todo',
                    'new_value' => $task->status,
                ],
                [
                    'user_id' => $task->assignee_id,
                    'description' => "Status changed from todo to {$task->status}.",
                ],
            );

            TaskActivity::firstOrCreate(
                [
                    'task_id' => $task->id,
                    'action' => 'priority_changed',
                    'old_value' => 'medium',
                    'new_value' => $task->priority,
                ],
                [
                    'user_id' => $task->reporter_id,
                    'description' => "Priority set to {$task->priority}.",
                ],
            );
        }

        $template = ProjectTemplate::updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'name' => 'Product Launch Template',
            ],
            [
                'description' => 'A reusable launch planning template for coordinating core launch work.',
                'created_by' => $defaultUser->id,
            ],
        );

        collect([
            ['title' => 'Define launch goals', 'position' => 1, 'offset_days' => 1],
            ['title' => 'Prepare content', 'position' => 2, 'offset_days' => 5],
            ['title' => 'Review design', 'position' => 3, 'offset_days' => 8],
            ['title' => 'Final launch checklist', 'position' => 4, 'offset_days' => 12],
        ])->each(fn (array $templateTask) => $template->tasks()->updateOrCreate(
            ['title' => $templateTask['title']],
            [
                'description' => null,
                'section' => 'Launch',
                'priority' => 'medium',
                'position' => $templateTask['position'],
                'offset_days' => $templateTask['offset_days'],
            ],
        ));
    }
}
