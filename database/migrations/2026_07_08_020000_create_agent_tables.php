<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agents')) {
            Schema::create('agents', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->string('status')->default('active');
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('agent_runs')) {
            Schema::create('agent_runs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
                $table->text('original_input');
                $table->string('status')->default('running');
                $table->json('result')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'status']);
                $table->index(['agent_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('agent_outputs')) {
            Schema::create('agent_outputs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
                $table->string('category');
                $table->json('detected_projects')->nullable();
                $table->string('priority');
                $table->string('due_label');
                $table->string('generated_task_title');
                $table->text('suggested_next_action');
                $table->json('payload')->nullable();
                $table->timestamps();

                $table->index(['category', 'priority']);
                $table->index('due_label');
            });
        }

        if (! Schema::hasTable('agent_run_logs')) {
            Schema::create('agent_run_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
                $table->string('level')->default('info');
                $table->text('message');
                $table->json('context')->nullable();
                $table->timestamp('occurred_at');
                $table->timestamps();

                $table->index(['agent_run_id', 'occurred_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_run_logs');
        Schema::dropIfExists('agent_outputs');
        Schema::dropIfExists('agent_runs');
        Schema::dropIfExists('agents');
    }
};
