<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waiting_items', function (Blueprint $table): void {
            $table->index(['user_id', 'status'], 'waiting_items_user_status_index');
            $table->index('follow_up_date', 'waiting_items_follow_up_date_index');
        });

        Schema::table('decisions', function (Blueprint $table): void {
            $table->index(['user_id', 'status'], 'decisions_user_status_index');
            $table->index('decision_due_date', 'decisions_due_date_index');
        });

        Schema::table('blockers', function (Blueprint $table): void {
            $table->index(['user_id', 'status'], 'blockers_user_status_index');
        });

        Schema::table('risks', function (Blueprint $table): void {
            $table->index(['user_id', 'status'], 'risks_user_status_index');
        });

        Schema::table('approvals', function (Blueprint $table): void {
            $table->index(['user_id', 'status'], 'approvals_user_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('approvals', fn (Blueprint $table) => $table->dropIndex('approvals_user_status_index'));
        Schema::table('risks', fn (Blueprint $table) => $table->dropIndex('risks_user_status_index'));
        Schema::table('blockers', fn (Blueprint $table) => $table->dropIndex('blockers_user_status_index'));
        Schema::table('decisions', function (Blueprint $table): void {
            $table->dropIndex('decisions_user_status_index');
            $table->dropIndex('decisions_due_date_index');
        });
        Schema::table('waiting_items', function (Blueprint $table): void {
            $table->dropIndex('waiting_items_user_status_index');
            $table->dropIndex('waiting_items_follow_up_date_index');
        });
    }
};
