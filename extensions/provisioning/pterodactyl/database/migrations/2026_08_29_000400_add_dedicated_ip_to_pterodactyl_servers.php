<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pterodactyl_servers', 'dedicated_ip')) {
            Schema::table('pterodactyl_servers', function (Blueprint $table): void {
                $table->boolean('dedicated_ip')->nullable()->after('node_id');
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Dedicated IP migration is irreversible.');
    }
};
