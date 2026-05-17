<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreignId('area_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('portfolio_id')->nullable()->after('area_id')->constrained()->nullOnDelete();
            $table->string('task_type')->nullable()->after('portfolio_id');
            $table->string('context')->nullable()->after('task_type');
            $table->string('energy_level')->nullable()->after('context');
            $table->integer('focus_score')->nullable()->after('energy_level');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('area_id');
            $table->dropConstrainedForeignId('portfolio_id');
            $table->dropColumn(['task_type', 'context', 'energy_level', 'focus_score']);
        });
    }
};
