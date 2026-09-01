<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class ServiceInstanceRuntimeSecretStore
{
    /** @return array{server_settings: array<string, mixed>|null, provider_settings: array<string, mixed>|null}|null */
    public function get(int $serviceInstanceId): ?array
    {
        $row = DB::table('service_instance_runtime_secrets')
            ->where('service_instance_id', $serviceInstanceId)
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'server_settings' => $this->decrypt($row->server_settings_encrypted),
            'provider_settings' => $this->decrypt($row->provider_settings_encrypted),
        ];
    }

    /** @param array<string, mixed>|null $serverSettings @param array<string, mixed>|null $providerSettings */
    public function put(int $serviceInstanceId, ?array $serverSettings, ?array $providerSettings): void
    {
        if ($serviceInstanceId < 1) {
            throw new RuntimeException('A valid service instance id is required.');
        }

        DB::table('service_instance_runtime_secrets')->updateOrInsert(
            ['service_instance_id' => $serviceInstanceId],
            [
                'server_settings_encrypted' => $this->encrypt($serverSettings),
                'provider_settings_encrypted' => $this->encrypt($providerSettings),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function forget(int $serviceInstanceId): void
    {
        DB::table('service_instance_runtime_secrets')
            ->where('service_instance_id', $serviceInstanceId)
            ->delete();
    }

    /** @param mixed $value @return array<string, mixed>|null */
    private function decrypt(mixed $value): ?array
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($value), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('Service runtime settings could not be decrypted.', previous: $exception);
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed>|null $value */
    private function encrypt(?array $value): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }

        return Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR));
    }
}
