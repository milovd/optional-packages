<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = $this->journalSchema();
        if ($schema->hasTable('plan_change_compensation_journals')) {
            return;
        }

        $schema->create('plan_change_compensation_journals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('journal_key')->unique();
            $table->text('payload_encrypted');
            $table->string('phase', 32)->index();
            $table->uuid('claim_token')->nullable()->index();
            $table->timestamp('claimed_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $this->journalSchema()->dropIfExists('plan_change_compensation_journals');
    }

    private function journalSchema(): \Illuminate\Database\Schema\Builder
    {
        $defaultConnection = (string) config('database.default');
        $defaultDatabase = config("database.connections.{$defaultConnection}.database");
        $isTestProcess = (app()->runningUnitTests() || app()->environment('testing') || config('app.env') === 'testing')
            && $defaultDatabase === ':memory:';
        $connection = $isTestProcess
            ? $defaultConnection
            : (string) config('database.compensation_connection', 'compensation_journal');

        $this->ensureSqliteDatabaseExists($connection);

        return Schema::connection($connection);
    }

    private function ensureSqliteDatabaseExists(string $connection): void
    {
        $databaseConfig = (array) config("database.connections.{$connection}", []);
        if (($databaseConfig['driver'] ?? null) !== 'sqlite') {
            return;
        }

        $database = $databaseConfig['database'] ?? null;
        if (! is_string($database) || $database === '' || $database === ':memory:') {
            return;
        }

        $directory = dirname($database);
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the compensation journal directory.');
        }

        if (! is_file($database) && file_put_contents($database, '', LOCK_EX) === false) {
            throw new RuntimeException('Unable to create the compensation journal database.');
        }
    }
};
