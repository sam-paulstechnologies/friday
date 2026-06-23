<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('miriam_reminders', function (Blueprint $table): void {
            if (! Schema::hasColumn('miriam_reminders', 'item_type')) {
                $table->string('item_type')->default('reminder')->after('category');
            }

            if (! Schema::hasColumn('miriam_reminders', 'confidence')) {
                $table->decimal('confidence', 4, 2)->default(1)->after('timezone');
            }

            if (! Schema::hasColumn('miriam_reminders', 'google_calendar_event_id')) {
                $table->string('google_calendar_event_id')->nullable()->after('source_message_ts');
            }
        });
    }

    public function down(): void
    {
        Schema::table('miriam_reminders', function (Blueprint $table): void {
            if (Schema::hasColumn('miriam_reminders', 'google_calendar_event_id')) {
                $table->dropColumn('google_calendar_event_id');
            }

            if (Schema::hasColumn('miriam_reminders', 'confidence')) {
                $table->dropColumn('confidence');
            }

            if (Schema::hasColumn('miriam_reminders', 'item_type')) {
                $table->dropColumn('item_type');
            }
        });
    }
};
