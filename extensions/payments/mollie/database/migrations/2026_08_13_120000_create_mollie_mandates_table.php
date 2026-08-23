<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mollie_mandates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->string('customer_email')->index();
            $table->string('mollie_customer_id');
            $table->string('mandate_id')->nullable();
            $table->timestamps();

            $table->unique(['customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mollie_mandates');
    }
};
