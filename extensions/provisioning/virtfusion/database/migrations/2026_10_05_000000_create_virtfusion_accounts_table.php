<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('virtfusion_accounts')) {
            return;
        }

        Schema::create('virtfusion_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('service_instance_id')->unique();
            $table->string('endpoint');
            $table->string('remote_id', 64);
            $table->string('remote_name')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_relation', 64)->nullable();
            $table->string('plan')->nullable();
            $table->string('state', 32);
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();

            $table->unique(['endpoint', 'remote_id']);
            $table->unique(['endpoint', 'user_relation']);
            $table->index(['endpoint', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('virtfusion_accounts');
    }
};
