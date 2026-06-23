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
            if (! Schema::hasColumn('miriam_development_ledgers', 'started_notification_dedupe_key')) {
                $table->string('started_notification_dedupe_key')->nullable()->after('summary_notified_at');
                $table->index('started_notification_dedupe_key', 'mdl_started_notice_dedupe_idx');
            }

            if (! Schema::hasColumn('miriam_development_ledgers', 'started_notified_at')) {
                $table->timestamp('started_notified_at')->nullable()->after('started_notification_dedupe_key');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('miriam_development_ledgers')) {
            return;
        }

        Schema::table('miriam_development_ledgers', function (Blueprint $table): void {
            if (Schema::hasColumn('miriam_development_ledgers', 'started_notification_dedupe_key')) {
                $table->dropIndex('mdl_started_notice_dedupe_idx');
                $table->dropColumn('started_notification_dedupe_key');
            }

            if (Schema::hasColumn('miriam_development_ledgers', 'started_notified_at')) {
                $table->dropColumn('started_notified_at');
            }
        });
    }
};
