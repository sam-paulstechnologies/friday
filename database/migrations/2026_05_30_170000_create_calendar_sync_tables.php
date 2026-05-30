<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('calendar_connections')) {
            Schema::create('calendar_connections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
                $table->string('provider')->default('google');
                $table->string('provider_account_email')->nullable();
                $table->text('access_token')->nullable();
                $table->text('refresh_token')->nullable();
                $table->timestamp('token_expires_at')->nullable();
                $table->json('scopes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_synced_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'provider', 'is_active'], 'cc_user_provider_active_idx');
                $table->index(['workspace_id', 'provider'], 'cc_workspace_provider_idx');
            });
        }

        if (! Schema::hasTable('calendar_sync_logs')) {
            Schema::create('calendar_sync_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('calendar_connection_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
                $table->string('direction');
                $table->string('status');
                $table->text('message')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'status'], 'csl_user_status_idx');
                $table->index(['calendar_connection_id', 'created_at'], 'csl_connection_created_idx');
                $table->index(['workspace_id', 'direction'], 'csl_workspace_direction_idx');
            });
        }

        if (! Schema::hasTable('calendar_event_mappings')) {
            Schema::create('calendar_event_mappings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
                $table->string('provider')->default('google');
                $table->string('provider_event_id');
                $table->string('provider_calendar_id')->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'provider', 'provider_event_id'], 'cem_user_provider_event_unique');
                $table->index(['user_id', 'provider', 'task_id'], 'cem_user_provider_task_idx');
                $table->index(['project_id', 'provider'], 'cem_project_provider_idx');
            });

            return;
        }

        Schema::table('calendar_event_mappings', function (Blueprint $table) {
            $table->unique(['user_id', 'provider', 'provider_event_id'], 'cem_user_provider_event_unique');
            $table->index(['user_id', 'provider', 'task_id'], 'cem_user_provider_task_idx');
            $table->index(['project_id', 'provider'], 'cem_project_provider_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_event_mappings');
        Schema::dropIfExists('calendar_sync_logs');
        Schema::dropIfExists('calendar_connections');
    }
};
