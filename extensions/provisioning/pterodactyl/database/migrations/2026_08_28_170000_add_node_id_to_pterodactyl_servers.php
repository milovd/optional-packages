<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pterodactyl_servers', 'node_id')) {
            Schema::table('pterodactyl_servers', function (Blueprint $table): void {
                $table->unsignedBigInteger('node_id')->nullable()->after('server_id');
                $table->index('node_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pterodactyl_servers', 'node_id')) {
            Schema::table('pterodactyl_servers', function (Blueprint $table): void {
                $table->dropIndex(['node_id']);
                $table->dropColumn('node_id');
            });
        }
    }
};
