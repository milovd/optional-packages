<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('service_instances') || Schema::hasColumn('service_instances', 'unit_index')) {
            return;
        }

        Schema::table('service_instances', function (Blueprint $table): void {
            $table->unsignedInteger('unit_index')->nullable()->after('order_item_id');
            $table->unique(['order_item_id', 'unit_index'], 'service_instances_order_item_unit_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('service_instances') || ! Schema::hasColumn('service_instances', 'unit_index')) {
            return;
        }

        Schema::table('service_instances', function (Blueprint $table): void {
            $table->dropUnique('service_instances_order_item_unit_unique');
            $table->dropColumn('unit_index');
        });
    }
};
