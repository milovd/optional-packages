<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shipments')) {
            return;
        }

        Schema::table('shipments', function (Blueprint $table): void {
            if (! Schema::hasColumn('shipments', 'carrier_id')) {
                $table->string('carrier_id', 64)->nullable()->after('carrier_name');
            }
            if (! Schema::hasColumn('shipments', 'external_ref')) {
                $table->string('external_ref', 128)->nullable()->after('carrier_id');
            }
            if (! Schema::hasColumn('shipments', 'label_path')) {
                $table->string('label_path')->nullable()->after('tracking_url');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('shipments')) {
            return;
        }

        $drop = [];
        foreach (['carrier_id', 'external_ref', 'label_path'] as $column) {
            if (Schema::hasColumn('shipments', $column)) {
                $drop[] = $column;
            }
        }
        if ($drop !== []) {
            Schema::table('shipments', function (Blueprint $table) use ($drop): void {
                $table->dropColumn($drop);
            });
        }
    }
};
