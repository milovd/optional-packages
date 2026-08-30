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
        if (Schema::hasTable('order_items') && Schema::hasColumn('order_items', 'provisioning_provider_settings_snapshot')) {
            DB::table('order_items')->orderBy('id')->each(function (object $item): void {
                $options = json_decode((string) $item->options_snapshot, true);
                $snapshot = is_array($options) ? ($options['__provisioning'] ?? null) : null;
                $encrypted = is_array($snapshot) ? ($snapshot['provider_settings_encrypted'] ?? null) : null;
                $settings = is_string($encrypted) && $encrypted !== '' ? $this->decrypt($encrypted) : null;
                if ($settings === null) {
                    return;
                }

                unset($snapshot['provider_settings_encrypted']);
                $snapshot['provider_settings'] = SensitiveDataRedactor::redact($settings);
                $options['__provisioning'] = $snapshot;
                DB::table('order_items')->where('id', $item->id)->update([
                    'provisioning_provider_settings_snapshot' => Crypt::encryptString(json_encode($settings, JSON_THROW_ON_ERROR)),
                    'options_snapshot' => json_encode($options, JSON_THROW_ON_ERROR),
                ]);
            });
        }

        if (Schema::hasTable('service_instances') && Schema::hasColumn('service_instances', 'provider_settings_snapshot')) {
            DB::table('service_instances')->orderBy('id')->each(function (object $instance): void {
                $meta = json_decode((string) $instance->meta, true);
                $encrypted = is_array($meta) ? ($meta['provider_settings_encrypted'] ?? null) : null;
                $settings = is_string($encrypted) && $encrypted !== '' ? $this->decrypt($encrypted) : null;
                if ($settings === null) {
                    return;
                }

                unset($meta['provider_settings_encrypted']);
                $meta = SensitiveDataRedactor::redact($meta);
                DB::table('service_instances')->where('id', $instance->id)->update([
                    'provider_settings_snapshot' => Crypt::encryptString(json_encode($settings, JSON_THROW_ON_ERROR)),
                    'meta' => json_encode($meta, JSON_THROW_ON_ERROR),
                ]);
            });
        }
    }

    public function down(): void
    {
    }

    /** @return array<string, mixed>|null */
    private function decrypt(string $encrypted): ?array
    {
        try {
            $settings = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($settings) ? $settings : null;
    }
};
