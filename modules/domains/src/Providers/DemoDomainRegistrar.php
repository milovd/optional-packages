<?php

declare(strict_types=1);

namespace Agovena\Modules\Domains\Providers;

use Agovena\Modules\Domains\Contracts\DomainRegistrar;
use Agovena\Modules\Domains\DomainName;
use Agovena\Modules\Domains\Models\DomainRegistration;
use Carbon\Carbon;

final class DemoDomainRegistrar implements DomainRegistrar
{
    public function key(): string
    {
        return 'demo-registrar';
    }

    public function capabilities(): array
    {
        return ['availability', 'registration', 'renewal'];
    }

    public function checkAvailability(string $domain): array
    {
        $domain = DomainName::normalize($domain);
        if ($domain === null) {
            return [
                'available' => false,
                'domain' => '',
                'price_minor' => null,
                'currency' => 'EUR',
                'reason' => 'invalid_domain',
            ];
        }

        $tld = DomainName::tld($domain);
        $available = in_array($tld, ['test', 'invalid'], true);

        return [
            'available' => $available,
            'domain' => $domain,
            'price_minor' => $available ? ($tld === 'test' ? 1299 : 999) : null,
            'currency' => 'EUR',
            'reason' => $available ? null : ($domain === 'agovena.com' ? 'registered' : 'demo_only_tld'),
        ];
    }

    public function register(DomainRegistration $registration): array
    {
        $domain = DomainName::normalize((string) $registration->domain_name);
        if ($domain === null || ! ($this->checkAvailability($domain)['available'] ?? false)) {
            return [
                'provider_reference' => null,
                'expires_at' => null,
                'status' => 'failed',
                'meta' => ['mode' => 'demo', 'calls_enabled' => false],
            ];
        }

        return [
            'provider_reference' => 'demo-reg-'.substr(hash('sha256', $domain), 0, 16),
            'expires_at' => Carbon::now()->addYear()->toIso8601String(),
            'status' => 'active',
            'meta' => [
                'mode' => 'demo',
                'calls_enabled' => false,
                'tld' => DomainName::tld($domain),
            ],
        ];
    }

    public function renew(DomainRegistration $registration, int $years = 1): array
    {
        return [
            'provider_reference' => $registration->provider_reference,
            'expires_at' => Carbon::parse($registration->expires_at ?? now())->addYears(max(1, $years))->toIso8601String(),
            'status' => 'active',
            'meta' => [
                'mode' => 'demo',
                'calls_enabled' => false,
            ],
        ];
    }
}
