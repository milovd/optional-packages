<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('plesk_accounts')) {
            return;
        }

        Schema::create('plesk_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('service_instance_id')->unique();
            $table->string('endpoint');
            $table->string('remote_id', 64);
            $table->string('remote_name')->nullable();
            $table->string('customer_login', 64);
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('plan')->nullable();
            $table->string('state', 32);
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();

            $table->unique(['endpoint', 'remote_id']);
            $table->unique(['endpoint', 'remote_name']);
            $table->unique(['endpoint', 'customer_login']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plesk_accounts');
    }
};
