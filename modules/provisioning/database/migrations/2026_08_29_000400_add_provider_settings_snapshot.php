<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_instances', function (Blueprint $table): void {
            $table->text('provider_settings_snapshot')->nullable()->after('server_settings_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('service_instances', function (Blueprint $table): void {
            $table->dropColumn('provider_settings_snapshot');
        });
    }
};
