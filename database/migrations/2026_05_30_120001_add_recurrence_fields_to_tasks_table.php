<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('recurrence_type')->default('none')->after('due_date');
            $table->unsignedSmallInteger('recurrence_interval')->default(1)->after('recurrence_type');
            $table->date('recurrence_ends_at')->nullable()->after('recurrence_interval');
            $table->foreignId('recurring_parent_id')->nullable()->after('recurrence_ends_at')->constrained('tasks')->nullOnDelete();
            $table->timestamp('last_generated_at')->nullable()->after('recurring_parent_id');

            $table->index(['recurrence_type', 'due_date']);
            $table->index(['recurring_parent_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropIndex(['recurrence_type', 'due_date']);
            $table->dropIndex(['recurring_parent_id', 'due_date']);
            $table->dropConstrainedForeignId('recurring_parent_id');
            $table->dropColumn([
                'recurrence_type',
                'recurrence_interval',
                'recurrence_ends_at',
                'last_generated_at',
            ]);
        });
    }
};
