<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_instances', function (Blueprint $table): void {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('customer_email');
            $table->string('customer_name')->nullable();
            $table->unsignedBigInteger('subscription_id')->nullable()->index();
            $table->string('status', 32);
            $table->string('provider_key')->nullable();
            $table->string('external_ref')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('provisioning_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_message')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['customer_id', 'status']);
            $table->index(['customer_email', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_instances');
    }
};
