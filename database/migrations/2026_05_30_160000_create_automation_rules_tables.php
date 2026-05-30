<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('automation_rules')) {
            Schema::create('automation_rules', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('trigger_type');
                $table->string('action_type');
                $table->json('conditions')->nullable();
                $table->json('action_payload')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_run_at')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();

                $table->index(['workspace_id', 'is_active']);
                $table->index(['workspace_id', 'trigger_type']);
                $table->index(['workspace_id', 'archived_at']);
            });
        }

        if (! Schema::hasTable('automation_runs')) {
            Schema::create('automation_runs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('automation_rule_id')->constrained()->cascadeOnDelete();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('target_type')->nullable();
                $table->unsignedBigInteger('target_id')->nullable();
                $table->string('target_key');
                $table->string('run_key');
                $table->string('status')->default('executed');
                $table->text('message')->nullable();
                $table->timestamps();

                $table->unique(['automation_rule_id', 'run_key', 'target_key', 'user_id'], 'automation_runs_unique_daily_target');
                $table->index(['workspace_id', 'created_at']);
                $table->index(['automation_rule_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_rules');
    }
};
