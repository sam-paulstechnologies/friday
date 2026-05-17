<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('review_date');
            $table->string('type');
            $table->string('status')->default('pending');
            $table->text('summary')->nullable();
            $table->string('slack_channel_id')->nullable();
            $table->string('slack_message_ts')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'review_date', 'type']);
            $table->index(['slack_channel_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_reviews');
    }
};
