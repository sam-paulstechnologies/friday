<?php

namespace App\Services\Agents;

class TestPlanAgent
{
    public function handle(array $context): array
    {
        $featureTests = [
            'route loads for the main page',
            'happy path creates the expected records',
            'approval/rejection changes status',
            'Today integration shows waiting review items',
        ];
        $routeChecks = ['php artisan route:list'];
        $buildChecks = ['npm run build'];
        $migrationChecks = ['php artisan migrate', 'guard new migrations with hasTable/hasColumn'];
        $manual = ['Open the page', 'Run the main action', 'Review output cards', 'Approve/reject one output'];

        $markdown = <<<MD
## Feature Tests Needed
- {$featureTests[0]}
- {$featureTests[1]}
- {$featureTests[2]}
- {$featureTests[3]}

## Route Checks
- php artisan route:list

## Auth Checks
- Authenticated users can load the pages.
- Guests are redirected to login.

## Role Checks
- User can only review outputs from their own runs.

## Database / Migration Checks
- php artisan migrate
- Guard new migrations with hasTable/hasColumn.

## NPM Build Check
- npm run build

## Laravel / Inertia Checks
- php -l on changed PHP files
- php artisan test --filter=<relevant>

## Browser / Manual Smoke Test
- {$manual[0]}
- {$manual[1]}
- {$manual[2]}
- {$manual[3]}

## Deploy Readiness Checklist
- No .env edits
- No destructive DB commands
- No automatic external actions

## Rollback Considerations
- Disable routes or revert the feature commit if review workflow blocks users.
MD;

        return [
            'agent_key' => 'test-plan',
            'agent_name' => 'Test Plan Agent',
            'title' => 'Validation plan',
            'category' => 'test_plan',
            'priority' => 'medium',
            'due_label' => 'no_due_date',
            'generated_task_title' => 'Review validation plan',
            'suggested_next_action' => 'Run the listed route, test, lint, build, and manual smoke checks before commit.',
            'payload' => [
                'feature_tests_needed' => $featureTests,
                'route_checks' => $routeChecks,
                'auth_checks' => ['authenticated users can load pages', 'guests redirect to login'],
                'role_checks' => ['user can only review own outputs'],
                'database_migration_checks' => $migrationChecks,
                'npm_build_check' => $buildChecks,
                'smoke_test_checklist' => $manual,
                'browser_manual_test_checklist' => $manual,
                'deploy_readiness_checklist' => ['no .env edits', 'no destructive DB commands', 'no automatic external actions'],
                'rollback_considerations' => ['revert feature commit', 'disable routes if needed'],
                'markdown' => $markdown,
            ],
        ];
    }
}
