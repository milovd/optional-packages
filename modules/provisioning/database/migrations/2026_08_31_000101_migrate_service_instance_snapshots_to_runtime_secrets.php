<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('service_instances')
            || ! Schema::hasTable('service_instance_runtime_secrets')
            || ! Schema::hasColumn('service_instances', 'server_settings_snapshot')
        ) {
            return;
        }

        $hasProviderSnapshot = Schema::hasColumn('service_instances', 'provider_settings_snapshot');
        $columns = ['id', 'server_settings_snapshot'];
        if ($hasProviderSnapshot) {
            $columns[] = 'provider_settings_snapshot';
        }

        DB::table('service_instances')->select($columns)->chunkById(500, function ($instances) use ($hasProviderSnapshot): void {
            foreach ($instances as $instance) {
                $serverSettings = $this->decrypt($instance->server_settings_snapshot ?? null);
            $providerSettings = $hasProviderSnapshot
                ? $this->decrypt($instance->provider_settings_snapshot ?? null)
                : null;
            if ($serverSettings === null && $providerSettings === null) {
                return;
            }

            DB::table('service_instance_runtime_secrets')->updateOrInsert(
                ['service_instance_id' => $instance->id],
                [
                    'server_settings_encrypted' => $this->encrypt($serverSettings),
                    'provider_settings_encrypted' => $this->encrypt($providerSettings),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
            $updates = [
                'server_settings_snapshot' => null,
                'updated_at' => now(),
            ];
            if ($hasProviderSnapshot) {
                $updates['provider_settings_snapshot'] = null;
            }
            DB::table('service_instances')->where('id', $instance->id)->update($updates);
            }
        });
    }

    public function down(): void
    {
    }

    /** @return array<string, mixed>|null */
    private function decrypt(mixed $value): ?array
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($value), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('Legacy service settings could not be migrated.', previous: $exception);
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed>|null $value */
    private function encrypt(?array $value): ?string
    {
        return $value === null || $value === []
            ? null
            : Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR));
    }
};
