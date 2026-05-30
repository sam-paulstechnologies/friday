<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('portfolio_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('spiritual_reading_day_id')->nullable();
            $table->string('title');
            $table->longText('content')->nullable();
            $table->longText('canvas_data')->nullable();
            $table->string('canvas_preview_path')->nullable();
            $table->string('note_type')->default('mixed');
            $table->json('tags')->nullable();
            $table->boolean('pinned')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'pinned', 'updated_at']);
            $table->index(['workspace_id', 'project_id']);
            $table->foreign('spiritual_reading_day_id', 'notes_spiritual_day_fk')->references('id')->on('bible_reading_plan_days')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
