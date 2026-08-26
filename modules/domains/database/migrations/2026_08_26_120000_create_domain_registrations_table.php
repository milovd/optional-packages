<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_registrations', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_email')->nullable();
            $table->string('customer_name')->nullable();
            $table->unsignedInteger('unit_index')->default(1);
            $table->string('domain_name', 253)->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('provider_key', 64)->nullable();
            $table->string('provider_reference', 191)->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->json('meta')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();
            $table->unique(['order_item_id', 'unit_index']);
            $table->index(['domain_name', 'status']);
            $table->index(['provider_key', 'status']);
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_registrations');
    }
};
