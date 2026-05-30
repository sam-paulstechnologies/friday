<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->default('openai');
            $table->text('encrypted_api_key')->nullable();
            $table->string('default_model')->default('gpt-4o-mini');
            $table->string('planner_model')->default('gpt-5.4-mini');
            $table->unsignedInteger('max_tasks_sent')->default(30);
            $table->unsignedInteger('max_output_tokens')->default(1200);
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();

            $table->unique(['workspace_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
    }
};
