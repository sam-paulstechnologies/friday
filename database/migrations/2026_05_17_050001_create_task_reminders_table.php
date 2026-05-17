<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reminder_type');
            $table->date('reminder_date');
            $table->timestamps();

            $table->unique(['task_id', 'user_id', 'reminder_type', 'reminder_date'], 'task_reminders_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_reminders');
    }
};
