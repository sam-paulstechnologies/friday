<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bible_books', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('testament');
            $table->unsignedSmallInteger('book_order')->unique();
            $table->unsignedSmallInteger('chapters_count');
            $table->timestamps();
        });

        Schema::create('bible_translations', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('language');
            $table->string('license')->nullable();
            $table->text('copyright')->nullable();
            $table->string('source_url')->nullable();
            $table->text('attribution')->nullable();
            $table->boolean('is_public_domain')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('bible_verses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bible_translation_id');
            $table->foreignId('bible_book_id');
            $table->unsignedSmallInteger('chapter_number');
            $table->unsignedSmallInteger('verse_number');
            $table->longText('text');
            $table->timestamps();

            $table->unique(['bible_translation_id', 'bible_book_id', 'chapter_number', 'verse_number'], 'bible_verse_unique');
            $table->index(['bible_book_id', 'chapter_number']);
            $table->foreign('bible_translation_id', 'bible_verses_translation_fk')->references('id')->on('bible_translations')->cascadeOnDelete();
            $table->foreign('bible_book_id', 'bible_verses_book_fk')->references('id')->on('bible_books')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bible_verses');
        Schema::dropIfExists('bible_translations');
        Schema::dropIfExists('bible_books');
    }
};
