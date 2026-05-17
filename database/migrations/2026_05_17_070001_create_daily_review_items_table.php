<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_review_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->integer('position')->nullable();
            $table->string('item_type')->nullable();
            $table->string('snapshot_title');
            $table->string('snapshot_status')->nullable();
            $table->string('snapshot_priority')->nullable();
            $table->date('snapshot_due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('response_text')->nullable();
            $table->timestamps();

            $table->index(['daily_review_id', 'position']);
            $table->index(['task_id', 'item_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_review_items');
    }
};
