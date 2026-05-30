<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            if (! Schema::hasColumn('portfolios', 'owner_id')) {
                $table->foreignId('owner_id')->nullable()->after('workspace_id')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            if (Schema::hasColumn('portfolios', 'owner_id')) {
                $table->dropConstrainedForeignId('owner_id');
            }
        });
    }
};
