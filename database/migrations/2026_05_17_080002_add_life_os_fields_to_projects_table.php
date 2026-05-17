<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->foreignId('area_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('portfolio_id')->nullable()->after('area_id')->constrained()->nullOnDelete();
            $table->string('project_type')->nullable()->after('portfolio_id');
            $table->string('health')->default('on_track')->after('project_type');
            $table->integer('sort_order')->default(0)->after('health');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('area_id');
            $table->dropConstrainedForeignId('portfolio_id');
            $table->dropColumn(['project_type', 'health', 'sort_order']);
        });
    }
};
