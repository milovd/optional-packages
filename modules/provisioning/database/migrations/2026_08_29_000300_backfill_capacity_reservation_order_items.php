<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('provisioning_capacity_reservations', 'order_item_id')) {
            return;
        }

        DB::table('provisioning_capacity_reservations')
            ->whereNull('order_item_id')
            ->orderBy('id')
            ->get(['id', 'order_id', 'product_id'])
            ->each(function (object $reservation): void {
                $itemIds = DB::table('order_items')
                    ->where('order_id', $reservation->order_id)
                    ->where('product_id', $reservation->product_id)
                    ->pluck('id');
                if ($itemIds->count() === 1) {
                    DB::table('provisioning_capacity_reservations')
                        ->where('id', $reservation->id)
                        ->whereNull('order_item_id')
                        ->update(['order_item_id' => $itemIds->first()]);
                }
            });
    }

    public function down(): void
    {
        // Backfilled order-item identity is retained on rollback for safety.
    }
};
