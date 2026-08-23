<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('postnl_shipments')) {
            return;
        }

        Schema::create('postnl_shipments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->unique();
            $table->string('barcode', 64);
            $table->string('product_code', 16)->nullable();
            $table->string('label_path')->nullable();
            $table->string('provider_status', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postnl_shipments');
    }
};
