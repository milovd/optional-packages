<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subscriptions') && ! Schema::hasColumn('subscriptions', 'payment_gateway')) {
            Schema::table('subscriptions', function (Blueprint $table): void {
                $table->string('payment_gateway', 32)->nullable()->after('quantity');
            });
        }

        if (Schema::hasTable('subscription_renewals') && ! Schema::hasColumn('subscription_renewals', 'charge_attempts')) {
            Schema::table('subscription_renewals', function (Blueprint $table): void {
                $table->unsignedTinyInteger('charge_attempts')->default(0);
                $table->timestamp('last_charged_at')->nullable();
                $table->timestamp('next_retry_at')->nullable();
                $table->string('last_error', 255)->nullable();
                $table->boolean('auto_charge_attempted')->default(false);
                $table->boolean('require_manual_payment')->default(false);
                $table->timestamp('failure_notified_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('subscriptions') && Schema::hasColumn('subscriptions', 'payment_gateway')) {
            Schema::table('subscriptions', function (Blueprint $table): void {
                $table->dropColumn('payment_gateway');
            });
        }

        if (Schema::hasTable('subscription_renewals') && Schema::hasColumn('subscription_renewals', 'charge_attempts')) {
            Schema::table('subscription_renewals', function (Blueprint $table): void {
                $table->dropColumn([
                    'charge_attempts',
                    'last_charged_at',
                    'next_retry_at',
                    'last_error',
                    'auto_charge_attempted',
                    'require_manual_payment',
                    'failure_notified_at',
                ]);
            });
        }
    }
};
