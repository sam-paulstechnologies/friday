<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('daily_health_logs')) {
            Schema::create('daily_health_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
                $table->date('log_date');
                $table->decimal('sleep_hours', 4, 2)->nullable();
                $table->unsignedTinyInteger('sleep_quality')->nullable();
                $table->unsignedTinyInteger('energy_score')->nullable();
                $table->unsignedTinyInteger('mood_score')->nullable();
                $table->unsignedTinyInteger('gym_readiness_score')->nullable();
                $table->boolean('gym_approved')->default(false);
                $table->text('gym_recommendation')->nullable();
                $table->string('workout_focus')->nullable();
                $table->text('workout_notes')->nullable();
                $table->string('medication_status')->nullable();
                $table->text('notes')->nullable();
                $table->string('source')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'workspace_id', 'log_date'], 'daily_health_user_workspace_date_unique');
                $table->index(['workspace_id', 'log_date']);
            });
        }

        if (! Schema::hasTable('medications')) {
            Schema::create('medications', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
                $table->string('name');
                $table->string('dosage')->nullable();
                $table->time('schedule_time')->nullable();
                $table->boolean('active')->default(true);
                $table->text('notes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'active']);
                $table->index(['workspace_id', 'active']);
            });
        }

        if (! Schema::hasTable('medication_logs')) {
            Schema::create('medication_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('medication_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
                $table->date('log_date');
                $table->string('status')->default('pending');
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('snoozed_until')->nullable();
                $table->text('notes')->nullable();
                $table->string('source')->nullable();
                $table->timestamps();

                $table->unique(['medication_id', 'user_id', 'log_date'], 'medication_logs_med_user_date_unique');
                $table->index(['user_id', 'log_date']);
                $table->index(['workspace_id', 'log_date']);
            });
        }

        if (! Schema::hasTable('workout_logs')) {
            Schema::create('workout_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
                $table->date('workout_date');
                $table->string('planned_focus')->nullable();
                $table->string('actual_focus')->nullable();
                $table->string('status')->default('planned');
                $table->unsignedSmallInteger('duration_minutes')->nullable();
                $table->string('intensity')->nullable();
                $table->text('notes')->nullable();
                $table->string('source')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'workout_date']);
                $table->index(['workspace_id', 'workout_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workout_logs');
        Schema::dropIfExists('medication_logs');
        Schema::dropIfExists('medications');
        Schema::dropIfExists('daily_health_logs');
    }
};
