<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('not_started')->index();
            $table->date('target_date')->nullable()->index();
            $table->unsignedTinyInteger('progress_percentage')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });

        Schema::create('goal_key_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->decimal('target_value', 12, 2)->default(100);
            $table->decimal('current_value', 12, 2)->default(0);
            $table->string('unit')->nullable();
            $table->string('status')->default('not_started')->index();
            $table->unsignedTinyInteger('progress_percentage')->default(0);
            $table->timestamps();
        });

        Schema::create('goal_project', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['goal_id', 'project_id']);
        });

        Schema::create('goal_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action')->index();
            $table->text('description')->nullable();
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goal_activities');
        Schema::dropIfExists('goal_project');
        Schema::dropIfExists('goal_key_results');
        Schema::dropIfExists('goals');
    }
};
