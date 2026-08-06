<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separate the daily workflow bucket from the user's own section labels.
 *
 * `tasks.section` is, in the live database, a free-text grouping label the
 * operator authored inside a project — "Phase 4 — Sales Kit", "Launch
 * Checklist", "Product UAT" and 26 others across 424 tasks. Writing canonical
 * workflow values (inbox/today/later/waiting/…) into that same column would
 * silently destroy one of those labels the first time a task moved to Today.
 *
 * This migration gives the workflow its own column and moves across only the
 * exact canonical strings the workflow code itself wrote. Human-authored
 * labels are never touched, matched loosely, or guessed at.
 */
return new class extends Migration
{
    /** The only values the workflow layer has ever written into `section`. */
    private const WORKFLOW_VALUES = [
        'inbox',
        'today',
        'this_week',
        'this_month',
        'later',
        'waiting',
        'delegated',
        'tasks',
        'dismissed',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('tasks', 'workflow_state')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->string('workflow_state', 32)->nullable()->after('section')->index();
            });
        }

        // Exact-match only. A task whose section is "Launch Checklist" keeps it.
        DB::table('tasks')
            ->whereIn('section', self::WORKFLOW_VALUES)
            ->update([
                'workflow_state' => DB::raw('section'),
                'section' => null,
            ]);
    }

    public function down(): void
    {
        // Put the workflow values back where they came from, but only where
        // doing so cannot overwrite a label the user has since written.
        DB::table('tasks')
            ->whereNotNull('workflow_state')
            ->whereNull('section')
            ->update(['section' => DB::raw('workflow_state')]);

        if (Schema::hasColumn('tasks', 'workflow_state')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->dropIndex(['workflow_state']);
                $table->dropColumn('workflow_state');
            });
        }
    }
};
