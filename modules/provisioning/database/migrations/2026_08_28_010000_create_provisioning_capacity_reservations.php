<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_capacity_locks', function (Blueprint $table): void {
            $table->id();
            $table->string('lock_key')->unique();
            $table->timestamps();
        });

        Schema::create('provisioning_capacity_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('provider_key');
            $table->string('capacity_key');
            $table->unsignedInteger('quantity');
            $table->dateTime('expires_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['order_id', 'product_id', 'provider_key', 'capacity_key'], 'provisioning_capacity_reservation_unique');
            $table->index(['capacity_key', 'provider_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_capacity_reservations');
        Schema::dropIfExists('provisioning_capacity_locks');
    }
};
