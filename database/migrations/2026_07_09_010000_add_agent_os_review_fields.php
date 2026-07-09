<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agent_runs')) {
            Schema::table('agent_runs', function (Blueprint $table): void {
                if (! Schema::hasColumn('agent_runs', 'parent_run_id')) {
                    $table->foreignId('parent_run_id')->nullable()->after('agent_id')->constrained('agent_runs')->nullOnDelete();
                }

                if (! Schema::hasColumn('agent_runs', 'context_label')) {
                    $table->string('context_label')->nullable()->after('workspace_id');
                }

                if (! Schema::hasColumn('agent_runs', 'mode')) {
                    $table->string('mode')->nullable()->after('context_label');
                }

                if (! Schema::hasColumn('agent_runs', 'selected_agent')) {
                    $table->string('selected_agent')->nullable()->after('mode');
                }
            });
        }

        if (Schema::hasTable('agent_outputs')) {
            Schema::table('agent_outputs', function (Blueprint $table): void {
                if (! Schema::hasColumn('agent_outputs', 'agent_key')) {
                    $table->string('agent_key')->nullable()->after('agent_run_id');
                }

                if (! Schema::hasColumn('agent_outputs', 'agent_name')) {
                    $table->string('agent_name')->nullable()->after('agent_key');
                }

                if (! Schema::hasColumn('agent_outputs', 'context_label')) {
                    $table->string('context_label')->nullable()->after('agent_name');
                }

                if (! Schema::hasColumn('agent_outputs', 'title')) {
                    $table->string('title')->nullable()->after('category');
                }

                if (! Schema::hasColumn('agent_outputs', 'status')) {
                    $table->string('status')->default('needs_review')->after('title');
                }

                if (! Schema::hasColumn('agent_outputs', 'sent_to_today_at')) {
                    $table->timestamp('sent_to_today_at')->nullable()->after('payload');
                }

                if (! Schema::hasColumn('agent_outputs', 'reviewed_by')) {
                    $table->foreignId('reviewed_by')->nullable()->after('sent_to_today_at')->constrained('users')->nullOnDelete();
                }

                if (! Schema::hasColumn('agent_outputs', 'reviewed_at')) {
                    $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
                }

                if (! Schema::hasColumn('agent_outputs', 'review_note')) {
                    $table->text('review_note')->nullable()->after('reviewed_at');
                }
            });

            Schema::table('agent_outputs', function (Blueprint $table): void {
                $table->index(['status', 'sent_to_today_at'], 'agent_outputs_status_today_index');
                $table->index(['agent_key', 'created_at'], 'agent_outputs_agent_key_created_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('agent_outputs')) {
            Schema::table('agent_outputs', function (Blueprint $table): void {
                $table->dropIndex('agent_outputs_status_today_index');
                $table->dropIndex('agent_outputs_agent_key_created_index');
            });

            Schema::table('agent_outputs', function (Blueprint $table): void {
                foreach ([
                    'review_note',
                    'reviewed_at',
                    'reviewed_by',
                    'sent_to_today_at',
                    'status',
                    'title',
                    'context_label',
                    'agent_name',
                    'agent_key',
                ] as $column) {
                    if (Schema::hasColumn('agent_outputs', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('agent_runs')) {
            Schema::table('agent_runs', function (Blueprint $table): void {
                foreach (['selected_agent', 'mode', 'context_label', 'parent_run_id'] as $column) {
                    if (Schema::hasColumn('agent_runs', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
