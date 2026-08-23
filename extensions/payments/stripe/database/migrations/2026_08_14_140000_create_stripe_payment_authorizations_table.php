<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stripe_payment_authorizations')) {
            return;
        }

        Schema::create('stripe_payment_authorizations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->string('customer_email')->index();
            $table->string('stripe_customer_id');
            $table->string('payment_method_id')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->unique(['customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_payment_authorizations');
    }
};
