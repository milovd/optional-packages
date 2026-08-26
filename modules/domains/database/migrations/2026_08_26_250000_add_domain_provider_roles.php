<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_registrations', function (Blueprint $table): void {
            $table->string('registrar_key', 64)->nullable();
            $table->string('dns_provider_key', 64)->nullable();
            $table->index(['registrar_key', 'status']);
            $table->index(['dns_provider_key', 'status']);
        });

        DB::table('domain_registrations')
            ->whereNull('registrar_key')
            ->whereNotNull('provider_key')
            ->update(['registrar_key' => DB::raw('provider_key')]);
    }

    public function down(): void
    {
        Schema::table('domain_registrations', function (Blueprint $table): void {
            $table->dropIndex(['registrar_key', 'status']);
            $table->dropIndex(['dns_provider_key', 'status']);
            $table->dropColumn(['registrar_key', 'dns_provider_key']);
        });
    }
};
