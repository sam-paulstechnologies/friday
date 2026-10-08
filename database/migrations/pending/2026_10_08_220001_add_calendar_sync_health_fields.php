<?php

// PENDING: deliberately outside the default migration scan. Promote only after explicit approval.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_connections', function (Blueprint $table): void {
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('last_successful_sync_at')->nullable();
            $table->string('sync_state')->default('unverified');
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->string('last_error_code')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('calendar_connections', function (Blueprint $table): void {
            $table->dropColumn(['last_attempted_at', 'last_successful_sync_at', 'sync_state',
                'consecutive_failures', 'next_retry_at', 'last_error_code']);
        });
    }
};
