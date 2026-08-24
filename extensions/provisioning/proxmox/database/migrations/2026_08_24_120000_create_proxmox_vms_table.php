<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxmox_vms', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('service_instance_id')->unique();
            $table->unsignedInteger('vmid');
            $table->string('node');
            $table->string('hostname');
            $table->string('external_id');
            $table->string('power_status')->nullable();
            $table->timestamps();

            $table->index('external_id');
            $table->index(['node', 'vmid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxmox_vms');
    }
};
