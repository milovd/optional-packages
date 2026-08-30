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
        if (! Schema::hasTable('service_instances') || ! Schema::hasColumn('service_instances', 'meta')) {
            return;
        }

        $hasServerSnapshot = Schema::hasColumn('service_instances', 'server_settings_snapshot');
        $columns = ['id', 'meta'];
        if ($hasServerSnapshot) {
            $columns[] = 'server_settings_snapshot';
        }

        DB::table('service_instances')
            ->whereNotNull('meta')
            ->orderBy('id')
            ->get($columns)
            ->each(function (object $row) use ($hasServerSnapshot): void {
                $decoded = json_decode((string) $row->meta, true);
                $providerSettings = is_array($decoded) && is_string($decoded['provider_settings_encrypted'] ?? null)
                    && trim($decoded['provider_settings_encrypted']) !== ''
                    ? $decoded['provider_settings_encrypted']
                    : (is_array($decoded) && is_array($decoded['provider_settings'] ?? null)
                        ? Crypt::encryptString(json_encode($decoded['provider_settings'], JSON_THROW_ON_ERROR))
                        : null);
                $snapshot = $hasServerSnapshot && is_string($row->server_settings_snapshot ?? null)
                    && trim($row->server_settings_snapshot) !== ''
                    ? $row->server_settings_snapshot
                    : (is_array($decoded) && is_array($decoded['server_settings'] ?? null)
                        ? Crypt::encryptString(json_encode($decoded['server_settings'], JSON_THROW_ON_ERROR))
                        : null);
                $value = is_array($decoded)
                    ? $this->redact($decoded)
                    : ['_redaction_status' => 'invalid_legacy_json'];
                if ($providerSettings !== null) {
                    $value['provider_settings_encrypted'] = $providerSettings;
                }

                $updates = [
                    'meta' => json_encode($value, JSON_THROW_ON_ERROR),
                ];
                if ($snapshot !== null && $hasServerSnapshot) {
                    $updates['server_settings_snapshot'] = $snapshot;
                }

                DB::table('service_instances')->where('id', $row->id)->update($updates);
            });
    }

    public function down(): void
    {
        // Redaction is intentionally irreversible.
    }

    private function redact(mixed $value, ?string $key = null): mixed
    {
        return SensitiveDataRedactor::redact($value, $key);
    }
};
