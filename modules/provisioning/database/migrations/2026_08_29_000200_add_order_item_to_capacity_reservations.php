<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('provisioning_capacity_reservations', 'order_item_id')) {
            Schema::table('provisioning_capacity_reservations', function (Blueprint $table): void {
                $table->dropUnique('provisioning_capacity_reservation_vector_unique');
                $table->foreignId('order_item_id')->nullable()->after('order_id')->constrained('order_items')->nullOnDelete();
                $table->index(['order_id', 'order_item_id', 'product_id'], 'provisioning_capacity_reservation_item_index');
                $table->unique(
                    ['order_id', 'order_item_id', 'product_id', 'provider_key', 'capacity_key', 'requirements_fingerprint'],
                    'provisioning_capacity_reservation_item_vector_unique',
                );
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Capacity reservation item identity migration is irreversible.');
    }
};
