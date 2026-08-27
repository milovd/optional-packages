<?php

declare(strict_types=1);

namespace Agovena\Extensions\DomainDns;

use Agovena\Modules\Domains\Contracts\DomainRegistrar;
use Agovena\Modules\Domains\Models\DomainRegistration;
use App\Support\MoneyFormatter;
use InvalidArgumentException;

final class CloudflareRegistrar implements DomainRegistrar
{
    public function __construct(
        private readonly CloudflareApi $api,
    ) {}

    public function key(): string
    {
        return 'cloudflare-registrar';
    }

    public function capabilities(): array
    {
        return ['availability_check', 'registration'];
    }

    public function checkAvailability(string $domain): array
    {
        $domain = $this->normalizeDomain($domain);
        $response = $this->api->check([$domain]);
        $entry = $this->firstDomain($response, $domain);
        $currency = strtoupper((string) ($entry['pricing']['currency'] ?? ''));
        $cost = $entry['pricing']['registration_cost'] ?? null;
        $priceMinor = null;
        if ($currency !== '' && is_string($cost) && $cost !== '') {
            try {
                $priceMinor = MoneyFormatter::minorFromMajorInput($cost, $currency);
            } catch (InvalidArgumentException) {
                $priceMinor = null;
            }
        }

        return [
            'available' => ($entry['registrable'] ?? false) === true,
            'domain' => (string) ($entry['name'] ?? $domain),
            'price_minor' => $priceMinor,
            'currency' => $currency !== '' ? $currency : null,
            'reason' => isset($entry['reason']) ? (string) $entry['reason'] : null,
        ];
    }

    public function register(DomainRegistration $registration): array
    {
        $domain = $this->normalizeDomain((string) $registration->domain_name);
        $response = $this->api->register($domain, [
            'auto_renew' => (bool) $registration->auto_renew,
        ]);
        $result = is_array($response['result'] ?? null) ? $response['result'] : $response;

        return [
            'provider_reference' => isset($result['id']) ? (string) $result['id'] : null,
            'expires_at' => isset($result['expires_at']) ? (string) $result['expires_at'] : null,
            'status' => isset($result['status']) ? (string) $result['status'] : null,
            'meta' => $result,
        ];
    }

    public function renew(DomainRegistration $registration, int $years = 1): array
    {
        throw new CloudflareRegistrarOperationNotSupported(
            'Cloudflare Registrar API beta does not support renewals.',
        );
    }

    /** @return array<string, mixed> */
    private function firstDomain(array $response, string $domain): array
    {
        $result = is_array($response['result'] ?? null) ? $response['result'] : $response;
        $domains = is_array($result['domains'] ?? null) ? $result['domains'] : [];
        foreach ($domains as $entry) {
            if (is_array($entry) && strtolower((string) ($entry['name'] ?? '')) === $domain) {
                return $entry;
            }
        }

        return [
            'name' => $domain,
            'registrable' => false,
            'reason' => 'domain_not_returned',
        ];
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
