<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('miriam_slack_clarifications')) {
            Schema::create('miriam_slack_clarifications', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('resolved_reminder_id')->nullable()->constrained('miriam_reminders')->nullOnDelete();
                $table->string('slack_user_id')->index();
                $table->string('slack_channel_id')->index();
                $table->string('source_message_ts')->nullable()->unique();
                $table->text('original_text');
                $table->text('clarification_question');
                $table->string('status')->default('pending')->index();
                $table->json('payload')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->index(['slack_user_id', 'slack_channel_id', 'status'], 'miriam_slack_clarifications_lookup_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('miriam_slack_clarifications');
    }
};
