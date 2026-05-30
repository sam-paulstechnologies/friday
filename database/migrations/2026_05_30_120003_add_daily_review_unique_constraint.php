<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasDuplicates = DB::table('daily_reviews')
            ->select('user_id', 'review_date', 'type')
            ->groupBy('user_id', 'review_date', 'type')
            ->havingRaw('count(*) > 1')
            ->exists();

        if ($hasDuplicates) {
            logger()->warning('Skipped daily_reviews unique constraint because duplicate user/date/type rows already exist.');

            return;
        }

        Schema::table('daily_reviews', function (Blueprint $table): void {
            $table->unique(['user_id', 'review_date', 'type'], 'daily_reviews_user_date_type_unique');
        });
    }

    public function down(): void
    {
        Schema::table('daily_reviews', function (Blueprint $table): void {
            try {
                $table->dropUnique('daily_reviews_user_date_type_unique');
            } catch (Throwable) {
                //
            }
        });
    }
};
