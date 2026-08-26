<?php

declare(strict_types=1);

namespace Agovena\Extensions\NamecheapRegistrar;

use Agovena\Modules\Domains\Contracts\DomainRegistrar;
use Agovena\Modules\Domains\Models\DomainRegistration;
use App\Support\MoneyFormatter;
use InvalidArgumentException;

final class NamecheapRegistrar implements DomainRegistrar
{
    public function __construct(
        private readonly NamecheapApi $api,
    ) {}

    public function key(): string
    {
        return 'namecheap-registrar';
    }

    public function capabilities(): array
    {
        return ['availability_check', 'registration', 'renewal'];
    }

    public function checkAvailability(string $domain): array
    {
        $domain = $this->normalizeDomain($domain);
        $entry = $this->firstDomain($this->api->check([$domain]), $domain);
        $currency = strtoupper((string) ($entry['currency'] ?? ''));
        $price = $entry['registration_price'] ?? null;
        $priceMinor = null;
        if ($currency !== '' && is_string($price) && $price !== '') {
            try {
                $priceMinor = MoneyFormatter::minorFromMajorInput($price, $currency);
            } catch (InvalidArgumentException) {
                $priceMinor = null;
            }
        }

        return [
            'available' => (bool) ($entry['available'] ?? false),
            'domain' => (string) ($entry['domain'] ?? $domain),
            'price_minor' => $priceMinor,
            'currency' => $currency !== '' ? $currency : null,
            'reason' => isset($entry['reason']) ? (string) $entry['reason'] : null,
        ];
    }

    public function register(DomainRegistration $registration): array
    {
        $domain = $this->normalizeDomain((string) $registration->domain_name);
        $years = $this->yearsFromRegistration($registration);
        $result = $this->api->register($domain, $years);
        $registered = (bool) ($result['registered'] ?? false);

        return [
            'provider_reference' => $this->providerReference($result),
            'expires_at' => null,
            'status' => $registered ? 'active' : 'failed',
            'meta' => $this->publicMeta($result),
        ];
    }

    public function renew(DomainRegistration $registration, int $years = 1): array
    {
        $domain = $this->normalizeDomain((string) $registration->domain_name);
        $result = $this->api->renew($domain, max(1, $years));
        $renewed = (bool) ($result['renewed'] ?? false);

        return [
            'provider_reference' => $this->providerReference($result),
            'expires_at' => null,
            'status' => $renewed ? 'active' : 'failed',
            'meta' => $this->publicMeta($result),
        ];
    }

    /** @param array<string, mixed> $response @return array<string, mixed> */
    private function firstDomain(array $response, string $domain): array
    {
        $domains = is_array($response['domains'] ?? null) ? $response['domains'] : [];
        foreach ($domains as $entry) {
            if (is_array($entry) && strtolower((string) ($entry['domain'] ?? '')) === $domain) {
                return $entry;
            }
        }

        return [
            'domain' => $domain,
            'available' => false,
            'reason' => 'domain_not_returned',
        ];
    }

    private function yearsFromRegistration(DomainRegistration $registration): int
    {
        $meta = is_array($registration->meta) ? $registration->meta : [];
        $settings = is_array($meta['provider_settings'] ?? null) ? $meta['provider_settings'] : [];

        return max(1, min(99, (int) ($settings['years'] ?? 1)));
    }

    /** @param array<string, mixed> $result */
    private function providerReference(array $result): ?string
    {
        foreach (['transaction_id', 'domain_id', 'order_id'] as $key) {
            if (isset($result[$key]) && is_scalar($result[$key]) && (string) $result[$key] !== '') {
                return (string) $result[$key];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private function publicMeta(array $result): array
    {
        return array_filter([
            'domain' => $result['domain'] ?? null,
            'domain_id' => $result['domain_id'] ?? null,
            'order_id' => $result['order_id'] ?? null,
            'transaction_id' => $result['transaction_id'] ?? null,
            'charged_amount' => $result['charged_amount'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function normalizeDomain(string $domain): string
    {
        $normalized = strtolower(rtrim(trim($domain), '.'));
        if ($normalized === '' || ! preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+\z/', $normalized)) {
            throw new InvalidArgumentException('A fully qualified ASCII domain is required.');
        }

        return $normalized;
    }
}
