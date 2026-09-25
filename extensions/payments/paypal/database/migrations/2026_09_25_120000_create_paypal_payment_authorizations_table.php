<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('paypal_payment_authorizations')) {
            return;
        }

        Schema::create('paypal_payment_authorizations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->string('customer_email')->index();
            $table->string('paypal_customer_id')->nullable();
            $table->text('payment_token_id')->nullable();
            $table->char('payment_token_hash', 64)->nullable()->unique();
            $table->string('status', 32)->default('active');
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->unique(['customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paypal_payment_authorizations');
    }
};
