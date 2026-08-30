<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provisioning_capacity_reservations', function (Blueprint $table): void {
            $table->json('requirements')->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('provisioning_capacity_reservations', function (Blueprint $table): void {
            $table->dropColumn('requirements');
        });
    }
};
