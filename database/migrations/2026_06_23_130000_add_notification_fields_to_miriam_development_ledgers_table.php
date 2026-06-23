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
            if (! Schema::hasColumn('miriam_development_ledgers', 'notification_dedupe_key')) {
                $table->string('notification_dedupe_key')->nullable()->after('next_action');
                $table->index('notification_dedupe_key', 'mdl_notification_dedupe_idx');
            }

            if (! Schema::hasColumn('miriam_development_ledgers', 'summary_notified_at')) {
                $table->timestamp('summary_notified_at')->nullable()->after('notification_dedupe_key');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('miriam_development_ledgers')) {
            return;
        }

        Schema::table('miriam_development_ledgers', function (Blueprint $table): void {
            if (Schema::hasColumn('miriam_development_ledgers', 'notification_dedupe_key')) {
                $table->dropIndex('mdl_notification_dedupe_idx');
                $table->dropColumn('notification_dedupe_key');
            }

            if (Schema::hasColumn('miriam_development_ledgers', 'summary_notified_at')) {
                $table->dropColumn('summary_notified_at');
            }
        });
    }
};
