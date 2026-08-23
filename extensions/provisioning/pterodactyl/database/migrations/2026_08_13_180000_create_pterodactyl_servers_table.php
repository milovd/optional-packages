<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pterodactyl_servers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('service_instance_id')->unique();
            $table->unsignedBigInteger('server_id');
            $table->string('identifier');
            $table->string('uuid')->nullable();
            $table->string('external_id');
            $table->string('panel_status')->nullable();
            $table->timestamps();

            $table->index('external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pterodactyl_servers');
    }
};
