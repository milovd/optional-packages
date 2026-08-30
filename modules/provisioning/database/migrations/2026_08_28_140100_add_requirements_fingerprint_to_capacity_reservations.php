<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('provisioning_capacity_reservations', 'requirements_fingerprint')) {
            Schema::table('provisioning_capacity_reservations', function (Blueprint $table): void {
                $table->dropUnique('provisioning_capacity_reservation_unique');
                $table->string('requirements_fingerprint', 64)->nullable()->after('requirements');
                $table->unique(
                    ['order_id', 'product_id', 'provider_key', 'capacity_key', 'requirements_fingerprint'],
                    'provisioning_capacity_reservation_vector_unique',
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('provisioning_capacity_reservations', 'requirements_fingerprint')) {
            Schema::table('provisioning_capacity_reservations', function (Blueprint $table): void {
                $table->dropUnique('provisioning_capacity_reservation_vector_unique');
                $table->unique(
                    ['order_id', 'product_id', 'provider_key', 'capacity_key'],
                    'provisioning_capacity_reservation_unique',
                );
                $table->dropColumn('requirements_fingerprint');
            });
        }
    }
};
