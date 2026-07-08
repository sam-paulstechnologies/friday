<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('medication_dose_logs')) {
            return;
        }

        try {
            Schema::table('medication_dose_logs', function (Blueprint $table): void {
                $table->dropUnique('med_dose_logs_schedule_date_unique');
            });
        } catch (\Throwable) {
            //
        }

        try {
            Schema::table('medication_dose_logs', function (Blueprint $table): void {
                $table->index(
                    ['dose_schedule_id', 'user_id', 'workspace_id', 'dose_date', 'status'],
                    'med_dose_logs_identity_status_idx'
                );
            });
        } catch (\Throwable) {
            //
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('medication_dose_logs')) {
            return;
        }

        try {
            Schema::table('medication_dose_logs', function (Blueprint $table): void {
                $table->dropIndex('med_dose_logs_identity_status_idx');
            });
        } catch (\Throwable) {
            //
        }
    }
};
