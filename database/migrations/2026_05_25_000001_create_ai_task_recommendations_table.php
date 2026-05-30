<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_task_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('recommendation_type');
            $table->text('current_value')->nullable();
            $table->text('suggested_value')->nullable();
            $table->text('reason');
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->string('status')->default('pending');
            $table->string('source')->default('manual');
            $table->text('raw_prompt')->nullable();
            $table->longText('raw_response')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'created_at']);
            $table->index(['task_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_task_recommendations');
    }
};
