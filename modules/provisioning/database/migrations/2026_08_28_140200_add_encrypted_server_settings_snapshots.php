<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_items') && ! Schema::hasColumn('order_items', 'provisioning_server_settings_snapshot')) {
            Schema::table('order_items', function (Blueprint $table): void {
                $table->text('provisioning_server_settings_snapshot')->nullable();
            });
        }

        if (Schema::hasTable('service_instances') && ! Schema::hasColumn('service_instances', 'server_settings_snapshot')) {
            Schema::table('service_instances', function (Blueprint $table): void {
                $table->text('server_settings_snapshot')->nullable();
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Encrypted server settings snapshot migration is irreversible.');
    }
};
