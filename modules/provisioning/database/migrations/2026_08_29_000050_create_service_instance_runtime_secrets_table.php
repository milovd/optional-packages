<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('service_instance_runtime_secrets')) {
            return;
        }

        Schema::create('service_instance_runtime_secrets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_instance_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('server_settings_encrypted')->nullable();
            $table->text('provider_settings_encrypted')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_instance_runtime_secrets');
    }
};
