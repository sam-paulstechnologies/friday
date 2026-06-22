<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('medication_dose_schedules') && ! Schema::hasColumn('medication_dose_schedules', 'hard_deadline_time')) {
            Schema::table('medication_dose_schedules', function (Blueprint $table): void {
                $table->time('hard_deadline_time')->nullable()->after('schedule_time');
            });
        }

        if (Schema::hasTable('medication_dose_schedules') && Schema::hasColumn('medication_dose_schedules', 'hard_deadline_time')) {
            DB::table('medication_dose_schedules')
                ->where('dose_key', 'morning')
                ->whereNull('hard_deadline_time')
                ->update(['hard_deadline_time' => '10:00:00']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('medication_dose_schedules') && Schema::hasColumn('medication_dose_schedules', 'hard_deadline_time')) {
            Schema::table('medication_dose_schedules', function (Blueprint $table): void {
                $table->dropColumn('hard_deadline_time');
            });
        }
    }
};
