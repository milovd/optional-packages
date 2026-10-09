<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Shipments belong to their order: deleting an order removes its shipment through
 * the database, like every other package table that references orders, instead of
 * Core cleaning up a PostNL table it does not own.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('postnl_shipments')
            ->whereNotIn('order_id', DB::table('orders')->select('id'))
            ->delete();

        Schema::table('postnl_shipments', function (Blueprint $table): void {
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('postnl_shipments', function (Blueprint $table): void {
            $table->dropForeign(['order_id']);
        });
    }
};
