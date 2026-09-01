<?php

declare(strict_types=1);

use App\Agovena\Security\SensitiveDataRedactor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->migrateServiceInstanceSecrets();
        $this->migrateOrderItemSecrets();
    }

    public function down(): void
    {
    }

    private function migrateServiceInstanceSecrets(): void
    {
        if (! Schema::hasTable('service_instances')
            || ! Schema::hasTable('service_instance_runtime_secrets')
            || ! Schema::hasColumn('service_instances', 'meta')
        ) {
            return;
        }

        $hasServerSnapshot = Schema::hasColumn('service_instances', 'server_settings_snapshot');
        $hasProviderSnapshot = Schema::hasColumn('service_instances', 'provider_settings_snapshot');
        $columns = ['id', 'meta'];
        if ($hasServerSnapshot) {
            $columns[] = 'server_settings_snapshot';
        }
        if ($hasProviderSnapshot) {
            $columns[] = 'provider_settings_snapshot';
        }

        DB::table('service_instances')->select($columns)->chunkById(500, function ($instances) use ($hasProviderSnapshot, $hasServerSnapshot): void {
            foreach ($instances as $instance) {
                $decoded = json_decode((string) $instance->meta, true);
                $runtime = DB::table('service_instance_runtime_secrets')
                    ->where('service_instance_id', $instance->id)
                    ->first();

                $providerSettings = $this->alreadyEncrypted($runtime?->provider_settings_encrypted)
                    ?? ($hasProviderSnapshot ? $this->alreadyEncrypted($instance->provider_settings_snapshot ?? null) : null)
                    ?? (is_array($decoded) ? $this->alreadyEncrypted($decoded['provider_settings_encrypted'] ?? null) : null)
                    ?? (is_array($decoded) ? $this->alreadyEncrypted($decoded['provider_settings_snapshot'] ?? null) : null)
                    ?? (is_array($decoded) ? $this->encryptArray($decoded['provider_settings'] ?? null) : null);
                $serverSettings = $this->alreadyEncrypted($runtime?->server_settings_encrypted)
                    ?? ($hasServerSnapshot ? $this->alreadyEncrypted($instance->server_settings_snapshot ?? null) : null)
                    ?? (is_array($decoded) ? $this->alreadyEncrypted($decoded['server_settings_snapshot'] ?? null) : null)
                    ?? (is_array($decoded) ? $this->encryptArray($decoded['server_settings'] ?? null) : null);

                if ($providerSettings !== null || $serverSettings !== null) {
                    $runtimeValues = [
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    if ($serverSettings !== null) {
                        $runtimeValues['server_settings_encrypted'] = $serverSettings;
                    }
                    if ($providerSettings !== null) {
                        $runtimeValues['provider_settings_encrypted'] = $providerSettings;
                    }

                    DB::table('service_instance_runtime_secrets')->updateOrInsert(
                        ['service_instance_id' => $instance->id],
                        $runtimeValues,
                    );
                }

                $value = is_array($decoded)
                    ? SensitiveDataRedactor::redact($decoded)
                    : ['_redaction_status' => 'invalid_legacy_json'];
                unset($value['provider_settings_encrypted']);
                $updates = [
                    'meta' => json_encode($value, JSON_THROW_ON_ERROR),
                ];
                if ($hasServerSnapshot) {
                    $updates['server_settings_snapshot'] = null;
                }
                if ($hasProviderSnapshot) {
                    $updates['provider_settings_snapshot'] = null;
                }

                DB::table('service_instances')->where('id', $instance->id)->update($updates);
            }
        });
    }

    private function migrateOrderItemSecrets(): void
    {
        if (! Schema::hasTable('order_items') || ! Schema::hasTable('order_item_runtime_secrets')) {
            return;
        }

        $hasSnapshot = Schema::hasColumn('order_items', 'provisioning_provider_settings_snapshot');
        $columns = ['id', 'options_snapshot'];
        if ($hasSnapshot) {
            $columns[] = 'provisioning_provider_settings_snapshot';
        }

        DB::table('order_items')->select($columns)->chunkById(500, function ($items) use ($hasSnapshot): void {
            foreach ($items as $item) {
                $options = json_decode((string) $item->options_snapshot, true);
                $snapshot = is_array($options) ? ($options['__provisioning'] ?? null) : null;
                $encrypted = is_array($snapshot) ? ($snapshot['provider_settings_encrypted'] ?? null) : null;
                if (! is_string($encrypted) || $encrypted === '') {
                    $encrypted = $hasSnapshot ? ($item->provisioning_provider_settings_snapshot ?? null) : null;
                }
                if (! is_string($encrypted) || $encrypted === '') {
                    continue;
                }

                if (! DB::table('order_item_runtime_secrets')
                    ->where('order_item_id', $item->id)
                    ->where('key', 'provisioning_provider_settings')
                    ->exists()
                ) {
                    DB::table('order_item_runtime_secrets')->insert([
                        'order_item_id' => $item->id,
                        'key' => 'provisioning_provider_settings',
                        'value_encrypted' => $encrypted,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $updates = [];
                if (is_array($snapshot) && isset($snapshot['provider_settings_encrypted'])) {
                    $settings = $this->decrypt($encrypted);
                    if ($settings !== null) {
                        unset($snapshot['provider_settings_encrypted']);
                        $snapshot['provider_settings'] = SensitiveDataRedactor::redact($settings);
                        $options['__provisioning'] = $snapshot;
                        $updates['options_snapshot'] = json_encode($options, JSON_THROW_ON_ERROR);
                    }
                }
                if ($hasSnapshot) {
                    $updates['provisioning_provider_settings_snapshot'] = null;
                }
                if ($updates !== []) {
                    DB::table('order_items')->where('id', $item->id)->update($updates);
                }
            }
        });
    }

    private function alreadyEncrypted(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function encryptArray(mixed $value): ?string
    {
        return is_array($value)
            ? Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR))
            : null;
    }

    /** @return array<string, mixed>|null */
    private function decrypt(string $encrypted): ?array
    {
        try {
            $decoded = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
};
