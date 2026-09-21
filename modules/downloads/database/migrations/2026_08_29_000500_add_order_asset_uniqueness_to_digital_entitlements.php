<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('digital_entitlements', function (Blueprint $table): void {
            $table->unique(['order_id', 'digital_asset_id'], 'digital_entitlements_order_asset_unique');
        });
    }

    public function down(): void
    {
        Schema::table('digital_entitlements', function (Blueprint $table): void {
            $table->dropUnique('digital_entitlements_order_asset_unique');
        });
    }
};
