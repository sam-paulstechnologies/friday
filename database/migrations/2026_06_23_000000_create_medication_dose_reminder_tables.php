<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('medication_dose_schedules')) {
            Schema::create('medication_dose_schedules', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('medication_id')->nullable()->constrained()->nullOnDelete();
                $table->string('dose_key');
                $table->string('label');
                $table->string('dosage_text');
                $table->string('timing_note')->nullable();
                $table->time('schedule_time')->nullable();
                $table->string('timezone')->default('Asia/Dubai');
                $table->boolean('active')->default(true);
                $table->unsignedSmallInteger('repeat_interval_minutes')->default(30);
                $table->time('quiet_hours_start')->nullable()->default('22:00:00');
                $table->time('quiet_hours_end')->nullable()->default('07:00:00');
                $table->boolean('hide_details_in_notifications')->default(true);
                $table->string('default_channel')->default('database');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'workspace_id', 'dose_key'], 'med_dose_user_workspace_key_unique');
                $table->index(['user_id', 'active', 'schedule_time'], 'med_dose_user_active_time_idx');
                $table->index(['workspace_id', 'active'], 'med_dose_workspace_active_idx');
            });
        }

        if (! Schema::hasTable('medication_dose_logs')) {
            Schema::create('medication_dose_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('dose_schedule_id')->constrained('medication_dose_schedules')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
                $table->date('dose_date');
                $table->timestamp('scheduled_for')->nullable();
                $table->string('scheduled_timezone')->default('Asia/Dubai');
                $table->string('status')->default('pending');
                $table->unsignedInteger('reminder_attempts')->default(0);
                $table->timestamp('first_reminded_at')->nullable();
                $table->timestamp('last_reminded_at')->nullable();
                $table->timestamp('next_reminder_at')->nullable();
                $table->timestamp('acknowledged_at')->nullable();
                $table->string('acknowledgement_source')->nullable();
                $table->string('acknowledgement_channel')->nullable();
                $table->string('last_delivery_channel')->nullable();
                $table->text('skip_reason')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['dose_schedule_id', 'dose_date'], 'med_dose_logs_schedule_date_unique');
                $table->index(['user_id', 'dose_date', 'status'], 'med_dose_logs_user_date_status_idx');
                $table->index(['status', 'next_reminder_at'], 'med_dose_logs_status_next_idx');
                $table->index('scheduled_for', 'med_dose_logs_scheduled_for_idx');
            });
        }

        if (! Schema::hasTable('medication_reminder_events')) {
            Schema::create('medication_reminder_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('dose_log_id')->nullable()->constrained('medication_dose_logs')->cascadeOnDelete();
                $table->foreignId('dose_schedule_id')->nullable()->constrained('medication_dose_schedules')->nullOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
                $table->string('event_type');
                $table->string('channel')->nullable();
                $table->string('device')->nullable();
                $table->timestamp('occurred_at');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'occurred_at'], 'med_reminder_events_user_time_idx');
                $table->index(['dose_log_id', 'event_type'], 'med_reminder_events_log_type_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_reminder_events');
        Schema::dropIfExists('medication_dose_logs');
        Schema::dropIfExists('medication_dose_schedules');
    }
};
