<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digital_secret_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->text('value_ciphertext');
            // Non-reversible sha256 of the normalized value; duplicate detection only.
            $table->string('value_fingerprint', 64)->nullable();
            $table->string('label')->nullable();
            $table->string('status')->default('available')->index();
            $table->timestamp('allocated_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status']);
            $table->index(['product_id', 'value_fingerprint']);
        });

        Schema::create('digital_secret_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('digital_secret_item_id')->nullable()->constrained('digital_secret_items')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('customer_email');
            $table->string('source')->default('pool');
            $table->string('status')->default('delivered');
            $table->text('value_ciphertext')->nullable();
            // Masked tail (e.g. ••••ABCD) so lists never need the plaintext.
            $table->string('value_hint')->nullable();
            $table->string('provider_id')->nullable();
            $table->string('provider_ref')->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            // A pool item may only ever back one delivery. NULLs stay distinct, so
            // manual/provider deliveries without a pool item are unaffected.
            $table->unique('digital_secret_item_id', 'dsd_secret_item_unique');
            $table->index(['order_id', 'order_item_id']);
            $table->index(['customer_id', 'status']);
            $table->index(['customer_email', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_secret_deliveries');
        Schema::dropIfExists('digital_secret_items');
    }
};
