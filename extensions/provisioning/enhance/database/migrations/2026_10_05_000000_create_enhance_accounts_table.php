<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('enhance_accounts')) {
            return;
        }

        Schema::create('enhance_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('service_instance_id')->unique();
            $table->string('endpoint');
            $table->string('remote_id', 64);
            $table->string('remote_name', 253)->nullable();
            $table->string('customer_org_id', 36)->nullable();
            $table->string('website_id', 36)->nullable();
            $table->string('plan', 32)->nullable();
            $table->string('state', 32);
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();

            $table->unique(['endpoint', 'remote_id']);
            $table->unique(['endpoint', 'remote_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enhance_accounts');
    }
};
