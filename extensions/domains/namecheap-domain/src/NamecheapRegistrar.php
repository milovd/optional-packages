<?php

declare(strict_types=1);

namespace Agovena\Extensions\NamecheapDomain;

use Agovena\Modules\Domains\Contracts\DomainRegistrar;
use Agovena\Modules\Domains\Contracts\RefreshesRegistrationStatus;
use Agovena\Modules\Domains\Models\DomainRegistration;
use App\Support\MoneyFormatter;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use RuntimeException;

final class NamecheapRegistrar implements DomainRegistrar, RefreshesRegistrationStatus
{
    /** TLDs for which domains.create documents mandatory extended attributes. */
    private const EXTENDED_ATTRIBUTE_TLDS = [
        'us', 'eu', 'ca', 'co.uk', 'org.uk', 'me.uk', 'nu', 'com.au', 'net.au', 'org.au',
        'es', 'nom.es', 'com.es', 'org.es', 'de', 'fr',
    ];

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
        $reason = $this->unsupportedReason($domain);
        if ($reason === null) {
            try {
                $reason = $this->refusalReason($this->checkEntry($domain));
            } catch (RuntimeException) {
                $reason = 'provider_unavailable';
            }
        }
        if ($reason !== null) {
            return ['available' => false, 'domain' => $domain, 'price_minor' => null, 'currency' => null, 'reason' => $reason];
        }

        try {
            $price = $this->api->registrationPrice($this->tld($domain), 1);
        } catch (RuntimeException) {
            $price = null;
        }
        $priceMinor = $price !== null ? $this->minor($price['price'], $price['currency']) : null;

        return [
            'available' => true,
            'domain' => $domain,
            'price_minor' => $priceMinor,
            'currency' => $priceMinor !== null ? $price['currency'] : null,
            'reason' => $priceMinor === null ? 'price_unavailable' : null,
        ];
    }

    /**
     * Safe to call again for the same domain: a domain already in this Namecheap account is
     * reported instead of registered twice, and the real-time check must confirm a regular,
     * available domain before the billable domains.create request is sent.
     */
    public function register(DomainRegistration $registration): array
    {
        $domain = $this->normalizeDomain((string) $registration->domain_name);
        $unsupported = $this->unsupportedReason($domain);
        if ($unsupported !== null) {
            throw new NamecheapOperationNotSupported('Namecheap registration of this domain is not supported ('.$unsupported.').');
        }
        $contact = NamecheapContact::fromRegistration($registration);

        $existing = $this->api->info($domain);
        if ($existing !== null) {
            return $this->activeResult($this->owned($existing), ['reconciled' => true]);
        }

        $reason = $this->refusalReason($this->checkEntry($domain));
        if ($reason !== null) {
            throw new RuntimeException('Namecheap reports this domain cannot be registered ('.$reason.').');
        }

        $result = $this->api->register($domain, $this->yearsFromRegistration($registration), $contact);
        $meta = $this->publicMeta($result);
        if (! $result['registered'] || $result['domain'] !== $domain) {
            return ['provider_reference' => $result['domain_id'], 'expires_at' => null, 'status' => 'failed', 'meta' => $meta];
        }
        if ($result['non_real_time']) {
            return ['provider_reference' => $result['domain_id'], 'expires_at' => null, 'status' => 'registering', 'meta' => $meta + ['non_real_time' => true]];
        }

        try {
            $info = $this->api->info($domain);
        } catch (RuntimeException) {
            $info = null;
        }
        if ($info === null || ! $info['is_owner'] || $info['expires_at'] === null) {
            return ['provider_reference' => $result['domain_id'], 'expires_at' => null, 'status' => 'registering', 'meta' => $meta];
        }

        return [
            'provider_reference' => $info['domain_id'] ?? $result['domain_id'],
            'expires_at' => $info['expires_at'],
            'status' => 'active',
            'meta' => $meta,
        ];
    }

    /**
     * Read-only domains.getInfo lookup for registrations that are still registering. It never
     * submits domains.create; a domain that is not yet in the account stays registering.
     */
    public function refreshRegistration(DomainRegistration $registration): array
    {
        $domain = $this->normalizeDomain((string) $registration->domain_name);
        $meta = is_array($registration->meta['provider_response'] ?? null) ? $registration->meta['provider_response'] : [];
        $info = $this->api->info($domain);
        if ($info === null) {
            return ['provider_reference' => $registration->provider_reference, 'expires_at' => null, 'status' => 'registering', 'meta' => $meta];
        }

        return $this->activeResult($this->owned($info), $meta);
    }

    /**
     * Renews only when the provider expiry still equals the recorded expiry. When the provider
     * expiry already equals the recorded expiry plus the requested years, an earlier renewal
     * whose response was lost is reported instead of charging again.
     */
    public function renew(DomainRegistration $registration, int $years = 1): array
    {
        $domain = $this->normalizeDomain((string) $registration->domain_name);
        $years = max(1, $years);
        $info = $this->owned($this->api->info($domain));
        if ($info['is_premium']) {
            throw new NamecheapOperationNotSupported('Premium domain renewal is not supported by the Namecheap integration.');
        }
        if ($registration->expires_at === null) {
            throw new RuntimeException('Namecheap renewal requires a known expiry date to prevent a duplicate charge.');
        }

        $recorded = CarbonImmutable::parse($registration->expires_at->toDateString(), 'UTC');
        $provider = $info['expires_at'] !== null ? CarbonImmutable::parse($info['expires_at'])->utc()->startOfDay() : null;
        if ($provider !== null && $this->sameDay($provider, $recorded->addYears($years))) {
            return $this->activeResult($info, ['reconciled' => true]);
        }
        if ($provider === null || ! $this->sameDay($provider, $recorded)) {
            throw new RuntimeException('The Namecheap expiry date does not match the recorded expiry; reconcile the domain before renewing.');
        }

        $result = $this->api->renew($domain, $years);
        $reference = $result['domain_id'] ?? $info['domain_id'];
        if (! $result['renewed'] || $result['domain'] !== $domain) {
            return [
                'provider_reference' => $reference,
                'expires_at' => $recorded->toIso8601String(),
                'status' => 'failed',
                'meta' => $this->publicMeta($result),
            ];
        }

        return [
            'provider_reference' => $reference,
            'expires_at' => $result['expires_at'] ?? $this->owned($this->api->info($domain))['expires_at'],
            'status' => 'active',
            'meta' => $this->publicMeta($result),
        ];
    }

    /**
     * @param  array{domain: string|null, domain_id: string|null, is_owner: bool, is_premium: bool, status: string|null, expires_at: string|null}|null  $info
     * @return array{domain: string|null, domain_id: string|null, is_owner: bool, is_premium: bool, status: string|null, expires_at: string|null}
     */
    private function owned(?array $info): array
    {
        if ($info === null || ! $info['is_owner']) {
            throw new RuntimeException('The domain is not in this Namecheap account or not owned by it.');
        }

        return $info;
    }

    /**
     * @param  array{domain: string|null, domain_id: string|null, is_owner: bool, is_premium: bool, status: string|null, expires_at: string|null}  $info
     * @param  array<string, mixed>  $meta
     * @return array{provider_reference: string|null, expires_at: string|null, status: string, meta: array<string, mixed>}
     */
    private function activeResult(array $info, array $meta): array
    {
        return [
            'provider_reference' => $info['domain_id'],
            'expires_at' => $info['expires_at'],
            'status' => 'active',
            'meta' => array_merge($meta, array_filter([
                'domain' => $info['domain'],
                'domain_id' => $info['domain_id'],
            ], static fn (?string $value): bool => $value !== null)),
        ];
    }

    /** @return array{domain: string|null, available: bool, premium: bool, error_no: string|null, premium_registration_price: string|null, eap_fee: string|null, icann_fee: string|null}|null */
    private function checkEntry(string $domain): ?array
    {
        foreach ($this->api->check([$domain])['domains'] as $entry) {
            if ($entry['domain'] === $domain) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Premium and Early Access domains carry their own price, which is never sold at the
     * regular account price; the API also refuses EAP registrations.
     *
     * @param  array{domain: string|null, available: bool, premium: bool, error_no: string|null, premium_registration_price: string|null, eap_fee: string|null, icann_fee: string|null}|null  $entry
     */
    private function refusalReason(?array $entry): ?string
    {
        return match (true) {
            $entry === null => 'domain_not_returned',
            ($entry['error_no'] ?? '0') !== '0' => 'provider_error',
            ! $entry['available'] => 'unavailable',
            $entry['premium'] || $entry['premium_registration_price'] !== null || $entry['eap_fee'] !== null => 'premium_not_supported',
            default => null,
        };
    }

    private function unsupportedReason(string $domain): ?string
    {
        if (preg_match('/(?:\A|\.)xn--/', $domain)) {
            return 'idn_not_supported';
        }

        return in_array($this->tld($domain), self::EXTENDED_ATTRIBUTE_TLDS, true) ? 'tld_not_supported' : null;
    }

    private function tld(string $domain): string
    {
        return substr($domain, strpos($domain, '.') + 1);
    }

    private function minor(string $price, string $currency): ?int
    {
        if (str_contains($price, '.')) {
            $price = rtrim(rtrim($price, '0'), '.');
        }

        try {
            return MoneyFormatter::minorFromMajorInput($price, $currency);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function sameDay(CarbonImmutable $left, CarbonImmutable $right): bool
    {
        return abs($left->startOfDay()->diffInDays($right->startOfDay(), false)) <= 1;
    }

    private function yearsFromRegistration(DomainRegistration $registration): int
    {
        $meta = is_array($registration->meta) ? $registration->meta : [];
        $settings = is_array($meta['provider_settings'] ?? null) ? $meta['provider_settings'] : [];

        return max(1, min(10, (int) ($settings['years'] ?? 1)));
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
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
