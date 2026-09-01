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

        return Schema::connection($connection);
    }
};
