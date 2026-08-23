<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subscription_renewals')) {
            return;
        }

        $duplicates = DB::table('subscription_renewals as later')
            ->join('subscription_renewals as earlier', function ($join): void {
                $join->on('later.subscription_id', '=', 'earlier.subscription_id')
                    ->on('later.period_start', '=', 'earlier.period_start')
                    ->whereColumn('later.id', '>', 'earlier.id');
            })
            ->pluck('later.id');

        if ($duplicates->isNotEmpty()) {
            DB::table('subscription_renewals')->whereIn('id', $duplicates)->delete();
        }

        Schema::table('subscription_renewals', function (Blueprint $table): void {
            $table->unique(['subscription_id', 'period_start'], 'subscription_renewals_period_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('subscription_renewals')) {
            return;
        }

        Schema::table('subscription_renewals', function (Blueprint $table): void {
            $table->dropUnique('subscription_renewals_period_unique');
        });
    }
};
