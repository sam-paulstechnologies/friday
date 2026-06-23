<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('miriam_tool_audits')) {
            Schema::create('miriam_tool_audits', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('slack_user_id')->nullable()->index();
                $table->string('slack_channel_id')->nullable()->index();
                $table->string('event_type')->index();
                $table->string('tool_name')->nullable()->index();
                $table->string('status')->nullable()->index();
                $table->text('summary')->nullable();
                $table->json('input')->nullable();
                $table->json('output')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('occurred_at')->index();
                $table->timestamps();

                $table->index(['slack_user_id', 'slack_channel_id', 'event_type'], 'miriam_tool_audits_slack_event_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('miriam_tool_audits');
    }
};
