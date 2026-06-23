<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('miriam_slack_conversation_contexts')) {
            Schema::create('miriam_slack_conversation_contexts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('slack_user_id')->index();
                $table->string('slack_channel_id')->index();
                $table->string('context_type')->index();
                $table->text('summary')->nullable();
                $table->longText('detail')->nullable();
                $table->json('payload')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();

                $table->index(['slack_user_id', 'slack_channel_id', 'context_type'], 'miriam_slack_context_lookup_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('miriam_slack_conversation_contexts');
    }
};
