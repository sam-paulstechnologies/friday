<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bible_reading_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('plan_type')->default('canonical');
            $table->unsignedSmallInteger('duration_days')->default(90);
            $table->date('starts_on')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'workspace_id', 'slug'], 'brp_owner_slug_unique');
        });

        Schema::create('bible_reading_plan_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bible_reading_plan_id');
            $table->unsignedSmallInteger('day_number');
            $table->date('reading_date')->nullable();
            $table->timestamps();

            $table->unique(['bible_reading_plan_id', 'day_number'], 'brpd_plan_day_unique');
            $table->index('reading_date');
            $table->foreign('bible_reading_plan_id', 'brpd_plan_fk')->references('id')->on('bible_reading_plans')->cascadeOnDelete();
        });

        Schema::create('bible_reading_plan_day_chapters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bible_reading_plan_day_id');
            $table->string('book_name');
            $table->unsignedSmallInteger('book_order');
            $table->unsignedSmallInteger('chapter_number');
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['bible_reading_plan_day_id', 'book_name', 'chapter_number'], 'brpdc_day_chapter_unique');
            $table->index(['book_order', 'chapter_number']);
            $table->foreign('bible_reading_plan_day_id', 'brpdc_day_fk')->references('id')->on('bible_reading_plan_days')->cascadeOnDelete();
        });

        Schema::create('bible_reading_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bible_reading_plan_day_chapter_id');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'bible_reading_plan_day_chapter_id'], 'brp_user_chapter_unique');
            $table->index(['user_id', 'read_at']);
            $table->foreign('bible_reading_plan_day_chapter_id', 'brp_chapter_fk')->references('id')->on('bible_reading_plan_day_chapters')->cascadeOnDelete();
        });

        Schema::create('spiritual_journals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bible_reading_plan_day_id')->nullable();
            $table->date('entry_date');
            $table->string('title')->nullable();
            $table->longText('content');
            $table->timestamps();

            $table->index(['user_id', 'entry_date']);
            $table->foreign('bible_reading_plan_day_id', 'spiritual_journals_day_fk')->references('id')->on('bible_reading_plan_days')->nullOnDelete();
        });

        Schema::create('spiritual_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bible_reading_plan_day_id')->nullable();
            $table->string('title');
            $table->longText('content')->nullable();
            $table->string('book_name')->nullable();
            $table->unsignedSmallInteger('chapter_number')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'book_name']);
            $table->foreign('bible_reading_plan_day_id', 'spiritual_notes_day_fk')->references('id')->on('bible_reading_plan_days')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spiritual_notes');
        Schema::dropIfExists('spiritual_journals');
        Schema::dropIfExists('bible_reading_progress');
        Schema::dropIfExists('bible_reading_plan_day_chapters');
        Schema::dropIfExists('bible_reading_plan_days');
        Schema::dropIfExists('bible_reading_plans');
    }
};
