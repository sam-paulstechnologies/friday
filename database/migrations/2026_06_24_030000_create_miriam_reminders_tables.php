<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('miriam_reminders')) {
            Schema::create('miriam_reminders', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('category')->default('unknown');
                $table->string('title');
                $table->string('timezone')->default('Asia/Dubai');
                $table->timestamp('due_at')->index();
                $table->string('status')->default('pending')->index();
                $table->unsignedInteger('reminder_attempts')->default(0);
                $table->timestamp('last_sent_at')->nullable();
                $table->timestamp('next_reminder_at')->nullable()->index();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->string('slack_user_id')->nullable()->index();
                $table->string('slack_channel_id')->nullable()->index();
                $table->string('source_message_ts')->nullable()->unique();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('miriam_reminder_events')) {
            Schema::create('miriam_reminder_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('miriam_reminder_id')->constrained('miriam_reminders')->cascadeOnDelete();
                $table->string('event_type');
                $table->string('channel')->nullable();
                $table->timestamp('occurred_at');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['miriam_reminder_id', 'event_type'], 'miriam_reminder_events_type_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('miriam_reminder_events');
        Schema::dropIfExists('miriam_reminders');
    }
};
