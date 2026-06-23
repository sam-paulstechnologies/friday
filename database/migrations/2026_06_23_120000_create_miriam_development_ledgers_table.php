<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('miriam_development_ledgers')) {
            return;
        }

        Schema::create('miriam_development_ledgers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_id')->nullable()->constrained('miriam_managed_apps')->nullOnDelete();
            $table->string('app_name')->nullable();
            $table->string('master_vision_reference')->nullable();
            $table->foreignId('job_id')->nullable()->constrained('miriam_development_jobs')->cascadeOnDelete();
            $table->foreignId('phase_run_id')->nullable()->constrained('miriam_development_phase_runs')->nullOnDelete();
            $table->foreignId('phase_id')->nullable()->constrained('miriam_prompt_phases')->nullOnDelete();
            $table->string('phase_name')->nullable();
            $table->string('status')->default('planned');
            $table->longText('summary')->nullable();
            $table->longText('files_changed_json')->nullable();
            $table->longText('tests_run_json')->nullable();
            $table->string('test_result')->nullable();
            $table->string('commit_hash')->nullable();
            $table->string('deployment_status')->default('not_deployed');
            $table->longText('blocker_reason')->nullable();
            $table->longText('next_action')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['app_id', 'status', 'created_at'], 'mdl_app_status_created_idx');
            $table->index(['job_id', 'status'], 'mdl_job_status_idx');
            $table->index(['status', 'created_at'], 'mdl_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('miriam_development_ledgers');
    }
};
