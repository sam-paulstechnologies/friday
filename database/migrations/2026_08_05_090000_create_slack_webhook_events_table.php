<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slack delivers at-least-once. Recording the event id per endpoint makes a
 * redelivery a no-op instead of a second acknowledgement, snooze or task.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('slack_webhook_events')) {
            return;
        }

        Schema::create('slack_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('endpoint')->index();
            $table->string('event_id');
            $table->string('event_type')->nullable();
            $table->string('outcome')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->unique(['endpoint', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slack_webhook_events');
    }
};
