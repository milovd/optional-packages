<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_servers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('provider_key', 64);
            $table->text('settings');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['provider_key', 'is_active']);
        });

        if (Schema::hasTable('service_instances') && ! Schema::hasColumn('service_instances', 'provisioning_server_id')) {
            Schema::table('service_instances', function (Blueprint $table): void {
                $table->foreignId('provisioning_server_id')
                    ->nullable()
                    ->after('provider_key')
                    ->constrained('provisioning_servers')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('service_instances') && Schema::hasColumn('service_instances', 'provisioning_server_id')) {
            Schema::table('service_instances', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('provisioning_server_id');
            });
        }

        Schema::dropIfExists('provisioning_servers');
    }
};
