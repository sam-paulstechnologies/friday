<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            if (! Schema::hasColumn('tasks', 'source')) {
                $table->string('source')->nullable()->after('position')->index();
            }

            if (! Schema::hasColumn('tasks', 'source_dedupe_key')) {
                $table->string('source_dedupe_key')->nullable()->after('source')->unique();
            }

            if (! Schema::hasColumn('tasks', 'source_metadata')) {
                $table->json('source_metadata')->nullable()->after('source_dedupe_key');
            }
        });

        Schema::table('miriam_reminders', function (Blueprint $table): void {
            if (! Schema::hasColumn('miriam_reminders', 'task_id')) {
                $table->foreignId('task_id')->nullable()->after('user_id')->constrained('tasks')->nullOnDelete();
            }

            if (! Schema::hasColumn('miriam_reminders', 'slack_workspace_id')) {
                $table->string('slack_workspace_id')->nullable()->after('slack_channel_id')->index();
            }

            if (! Schema::hasColumn('miriam_reminders', 'source_thread_ts')) {
                $table->string('source_thread_ts')->nullable()->after('source_message_ts')->index();
            }

            if (! Schema::hasColumn('miriam_reminders', 'source_dedupe_key')) {
                $table->string('source_dedupe_key')->nullable()->after('source_thread_ts')->unique();
            }
        });
    }

    public function down(): void
    {
        Schema::table('miriam_reminders', function (Blueprint $table): void {
            if (Schema::hasColumn('miriam_reminders', 'source_dedupe_key')) {
                $table->dropUnique(['source_dedupe_key']);
                $table->dropColumn('source_dedupe_key');
            }

            if (Schema::hasColumn('miriam_reminders', 'source_thread_ts')) {
                $table->dropIndex(['source_thread_ts']);
                $table->dropColumn('source_thread_ts');
            }

            if (Schema::hasColumn('miriam_reminders', 'slack_workspace_id')) {
                $table->dropIndex(['slack_workspace_id']);
                $table->dropColumn('slack_workspace_id');
            }

            if (Schema::hasColumn('miriam_reminders', 'task_id')) {
                $table->dropConstrainedForeignId('task_id');
            }
        });

        Schema::table('tasks', function (Blueprint $table): void {
            if (Schema::hasColumn('tasks', 'source_metadata')) {
                $table->dropColumn('source_metadata');
            }

            if (Schema::hasColumn('tasks', 'source_dedupe_key')) {
                $table->dropUnique(['source_dedupe_key']);
                $table->dropColumn('source_dedupe_key');
            }

            if (Schema::hasColumn('tasks', 'source')) {
                $table->dropIndex(['source']);
                $table->dropColumn('source');
            }
        });
    }
};
