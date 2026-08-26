<?php

declare(strict_types=1);

namespace Agovena\Modules\Domains;

use Agovena\Modules\Domains\Contracts\DomainRegistrar;
use Agovena\Modules\Domains\Enums\DomainRegistrationStatus;
use Agovena\Modules\Domains\Models\DomainRegistration;
use App\Models\Order;
use App\Models\Product;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class DomainService
{
    public function __construct(
        private readonly DomainRegistrarRegistry $registrars,
        private readonly DomainDnsProviderRegistry $dnsProviders,
    ) {}

    public function createFromPaidOrder(Order $order): void
    {
        $order->loadMissing('items');

        foreach ($order->items as $item) {
            if ($item->product_id === null) {
                continue;
            }

            $product = Product::query()->with('capabilities')->find($item->product_id);
            if ($product === null || ! $product->hasCapability('domain_registration')) {
                continue;
            }

            $capability = $product->capability('domain_registration');
            $config = $capability !== null && is_array($capability->config)
                ? $capability->config
                : [];
            $providerKey = isset($config['provider_key']) && is_string($config['provider_key'])
                ? trim($config['provider_key'])
                : null;
            $providerKey = $providerKey !== '' ? $providerKey : null;
            $registrarKey = isset($config['registrar_key']) && is_string($config['registrar_key'])
                ? trim($config['registrar_key'])
                : $providerKey;
            $registrarKey = $registrarKey !== '' ? $registrarKey : null;
            $dnsProviderKey = isset($config['dns_provider_key']) && is_string($config['dns_provider_key'])
                ? trim($config['dns_provider_key'])
                : null;
            $dnsProviderKey = $dnsProviderKey !== '' ? $dnsProviderKey : null;
            $providerSettings = is_array($config['provider_settings'] ?? null)
                ? $config['provider_settings']
                : [];
            $optionsSnapshot = is_array($item->options_snapshot ?? null)
                ? $item->options_snapshot
                : [];
            $domainName = $this->domainFromSnapshot($optionsSnapshot)
                ?? $this->normalizeDomain($config['domain_name'] ?? null);
            $quantity = max(1, (int) $item->quantity);
            $existingUnits = DomainRegistration::query()
                ->where('order_item_id', $item->id)
                ->count();

            for ($unitIndex = $existingUnits + 1; $unitIndex <= $quantity; $unitIndex++) {
                DomainRegistration::query()->create([
                    'number' => $this->generateNumber(),
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'product_id' => $product->id,
                    'customer_id' => $order->customer_id,
                    'customer_email' => $order->customer_email,
                    'customer_name' => $order->customer_name,
                    'unit_index' => $unitIndex,
                    'domain_name' => $domainName,
                    'status' => DomainRegistrationStatus::Pending,
                    'provider_key' => $registrarKey,
                    'registrar_key' => $registrarKey,
                    'dns_provider_key' => $dnsProviderKey,
                    'auto_renew' => (bool) ($config['auto_renew'] ?? false),
                    'meta' => [
                        'label' => $item->label,
                        'unit_amount' => $item->unit_amount,
                        'currency' => $item->currency,
                        'options_snapshot' => $optionsSnapshot,
                        'provider_settings' => $providerSettings,
                        'registrar_key' => $registrarKey,
                        'dns_provider_key' => $dnsProviderKey,
                        'awaiting_domain_name' => $domainName === null,
                    ],
                ]);
            }
        }
    }

    public function register(DomainRegistration $registration): DomainRegistration
    {
        try {
            $registrar = $this->registrarFor($registration, 'registration');
            $registration->forceFill([
                'status' => DomainRegistrationStatus::Registering,
                'failure_message' => null,
                'failed_at' => null,
            ])->save();

            $result = $registrar->register($registration->fresh() ?? $registration);

            return $this->applyRegistrarResult($registration, $result, DomainRegistrationStatus::Active);
        } catch (Throwable $exception) {
            $this->markFailed($registration, $exception);
            throw $exception;
        }
    }

    public function renew(DomainRegistration $registration, int $years = 1): DomainRegistration
    {
        try {
            $registrar = $this->registrarFor($registration, 'renewal');
            $result = $registrar->renew($registration->fresh() ?? $registration, max(1, min(99, $years)));

            return $this->applyRegistrarResult($registration, $result, DomainRegistrationStatus::Active);
        } catch (Throwable $exception) {
            $this->markFailed($registration, $exception);
            throw $exception;
        }
    }

    public function ensureDnsZone(DomainRegistration $registration): DomainRegistration
    {
        $key = trim((string) $registration->dns_provider_key);
        if ($key === '') {
            throw new RuntimeException('No DNS provider is configured for this domain.');
        }

        $provider = $this->dnsProviders->get($key);
        if ($provider === null) {
            throw new RuntimeException('DNS provider ['.$key.'] is not available.');
        }
        if (! in_array('zone_management', $provider->capabilities(), true)) {
            throw new RuntimeException('DNS provider ['.$key.'] does not support zone management.');
        }

        $zone = $provider->ensureZone($registration->fresh() ?? $registration);
        $meta = is_array($registration->meta) ? $registration->meta : [];
        $meta['dns_zone'] = [
            'zone_reference' => isset($zone['zone_reference']) ? (string) $zone['zone_reference'] : null,
            'nameservers' => is_array($zone['nameservers'] ?? null)
                ? array_values(array_map('strval', $zone['nameservers']))
                : [],
            'status' => isset($zone['status']) ? (string) $zone['status'] : null,
            'meta' => is_array($zone['meta'] ?? null) ? $zone['meta'] : [],
        ];
        $registration->forceFill(['meta' => $meta])->save();

        return $registration->fresh() ?? $registration;
    }

    private function registrarFor(DomainRegistration $registration, string $capability): DomainRegistrar
    {
        $key = trim((string) ($registration->registrar_key ?: $registration->provider_key));
        if ($key === '') {
            throw new RuntimeException('No registrar is configured for this domain.');
        }

        $registrar = $this->registrars->get($key);
        if ($registrar === null) {
            throw new RuntimeException('Registrar ['.$key.'] is not available.');
        }
        if (! in_array($capability, $registrar->capabilities(), true)) {
            throw new RuntimeException('Registrar ['.$key.'] does not support '.$capability.'.');
        }

        return $registrar;
    }

    /** @param array<string, mixed> $result */
    private function applyRegistrarResult(
        DomainRegistration $registration,
        array $result,
        DomainRegistrationStatus $fallbackStatus,
    ): DomainRegistration {
        $status = DomainRegistrationStatus::tryFrom((string) ($result['status'] ?? '')) ?? $fallbackStatus;
        $meta = is_array($registration->meta) ? $registration->meta : [];
        $providerMeta = is_array($result['meta'] ?? null) ? $result['meta'] : [];
        $meta['provider_response'] = $providerMeta;

        $registration->forceFill([
            'status' => $status,
            'provider_reference' => isset($result['provider_reference']) ? (string) $result['provider_reference'] : null,
            'expires_at' => $this->parseDate($result['expires_at'] ?? null),
            'registered_at' => $status === DomainRegistrationStatus::Active
                ? ($registration->registered_at ?? now())
                : $registration->registered_at,
            'failure_message' => $status === DomainRegistrationStatus::Failed
                ? 'The registrar reported a failed operation.'
                : null,
            'failed_at' => $status === DomainRegistrationStatus::Failed ? now() : null,
            'meta' => $meta,
        ])->save();

        return $registration->fresh() ?? $registration;
    }

    private function markFailed(DomainRegistration $registration, Throwable $exception): void
    {
        $registration->forceFill([
            'status' => DomainRegistrationStatus::Failed,
            'failed_at' => now(),
            'failure_message' => Str::limit($exception->getMessage(), 500, ''),
        ])->save();
    }

    private function parseDate(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<array<string, mixed>> $snapshot */
    private function domainFromSnapshot(array $snapshot): ?string
    {
        foreach ($snapshot as $option) {
            $key = strtolower(trim((string) ($option['key'] ?? '')));
            if (! in_array($key, ['domain', 'domain_name'], true)) {
                continue;
            }

            return $this->normalizeDomain($option['value'] ?? null);
        }

        return null;
    }

    private function normalizeDomain(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $domain = strtolower(trim($value));
        return $domain !== '' ? rtrim($domain, '.') : null;
    }

    private function generateNumber(): string
    {
        do {
            $number = 'DOM-'.strtoupper(Str::random(10));
        } while (DomainRegistration::query()->where('number', $number)->exists());

        return $number;
    }
}
