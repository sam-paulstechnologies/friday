<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('miriam_development_ledgers')) {
            return;
        }

        Schema::table('miriam_development_ledgers', function (Blueprint $table): void {
            if (! Schema::hasColumn('miriam_development_ledgers', 'development_name')) {
                $table->string('development_name')->nullable()->after('phase_name');
            }

            if (! Schema::hasColumn('miriam_development_ledgers', 'short_description')) {
                $table->text('short_description')->nullable()->after('development_name');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('miriam_development_ledgers')) {
            return;
        }

        Schema::table('miriam_development_ledgers', function (Blueprint $table): void {
            if (Schema::hasColumn('miriam_development_ledgers', 'short_description')) {
                $table->dropColumn('short_description');
            }

            if (Schema::hasColumn('miriam_development_ledgers', 'development_name')) {
                $table->dropColumn('development_name');
            }
        });
    }
};
