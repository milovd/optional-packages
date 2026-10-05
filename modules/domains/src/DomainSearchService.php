<?php

declare(strict_types=1);

namespace Agovena\Modules\Domains;

use App\Agovena\Money\CurrencyConverter;
use App\Models\Product;
use App\Support\MoneyFormatter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DomainSearchService
{
    public function __construct(
        private readonly DomainRegistrarRegistry $registrars,
        private readonly CurrencyConverter $converter,
    ) {}

    /** @return array<string, mixed> */
    public function search(string $query, ?int $productId = null): array
    {
        $domain = DomainName::normalize($query);
        if ($domain === null) {
            throw ValidationException::withMessages([
                'query' => __('domains::storefront.validation.domain'),
            ]);
        }

        $product = $this->domainProduct($productId);
        if ($product === null) {
            throw ValidationException::withMessages([
                'query' => __('domains::storefront.unavailable_configuration'),
            ]);
        }

        $config = $this->configuration($product);
        $requested = $this->resultFor($domain, $product, $config);
        $base = DomainName::baseLabel($domain) ?? $domain;
        $alternatives = [];
        foreach ($this->allowedTlds($config) as $tld) {
            $candidate = $base.'.'.$tld;
            if ($candidate === $domain) {
                continue;
            }
            $alternatives[] = $this->resultFor($candidate, $product, $config);
        }

        return [
            'query' => $domain,
            'requested' => $requested,
            'alternatives' => array_values(array_filter($alternatives, static fn (array $item): bool => $item['available'])),
            'product_name' => $product->name,
        ];
    }

    /** @param array<string, mixed> $result */
    public function issueSelection(array $result, ?int $productId = null): ?string
    {
        if (! ($result['available'] ?? false) || ! is_string($result['domain'] ?? null)) {
            return null;
        }

        $product = $this->domainProduct($productId);
        $domain = DomainName::normalize($result['domain']);
        if ($product === null || $domain === null) {
            return null;
        }

        $config = $this->configuration($product);
        $check = $this->resultFor($domain, $product, $config);
        if (! $check['available']) {
            return null;
        }

        $token = Str::random(48);
        session()->put('domains.quotes.'.$token, [
            'domain' => $domain,
            'product_id' => $product->id,
            'price_minor' => $check['price_minor'],
            'currency' => $check['currency'],
            'registrar_key' => $config['registrar_key'],
            'dns_provider_key' => $config['dns_provider_key'],
            'expires_at' => now()->addMinutes(10)->timestamp,
        ]);

        return $token;
    }

    /** @return array<string, mixed>|null */
    public function selection(string $token): ?array
    {
        $selection = session()->get('domains.quotes.'.$token);
        if (! is_array($selection) || (int) ($selection['expires_at'] ?? 0) < now()->timestamp) {
            session()->forget('domains.quotes.'.$token);

            return null;
        }

        return $selection;
    }

    /** @return array<string, mixed>|null */
    public function quoteFor(Product $product, string $domain): ?array
    {
        $domain = DomainName::normalize($domain);
        if ($domain === null) {
            return null;
        }

        $latest = null;
        $latestExpiry = -1;

        foreach ((array) session()->get('domains.quotes', []) as $quote) {
            if (! is_array($quote)
                || ($quote['domain'] ?? null) !== $domain
                || (int) ($quote['product_id'] ?? 0) !== $product->id
                || (int) ($quote['expires_at'] ?? 0) < now()->timestamp
            ) {
                continue;
            }

            $priceMinor = $quote['price_minor'] ?? null;
            if (is_string($priceMinor) && ctype_digit($priceMinor)) {
                $priceMinor = (int) $priceMinor;
            }
            if (! is_int($priceMinor) || $priceMinor < 0) {
                continue;
            }

            $currency = strtoupper(trim((string) ($quote['currency'] ?? '')));
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                continue;
            }

            $expiresAt = (int) ($quote['expires_at'] ?? 0);
            if ($expiresAt < $latestExpiry) {
                continue;
            }

            $latestExpiry = $expiresAt;
            $latest = [
                ...$quote,
                'price_minor' => $priceMinor,
                'currency' => $currency,
            ];
        }

        return $latest;
    }

    public function domainProduct(?int $productId = null): ?Product
    {
        $query = Product::query()
            ->with(['capabilities', 'currencyPrices'])
            ->where('status', 'active')
            ->whereHas('capabilities', static fn ($query) => $query->where('capability', 'domain_registration'));

        if ($productId !== null) {
            $query->whereKey($productId);
        }

        return $query
            ->orderBy('id')
            ->first();
    }

    /** @param array<string, mixed> $config */
    private function resultFor(string $domain, Product $product, array $config): array
    {
        $registrar = $this->registrars->get($config['registrar_key']);
        $result = $registrar?->checkAvailability($domain) ?? [
            'available' => false,
            'domain' => $domain,
            'price_minor' => null,
            'currency' => $product->currency,
            'reason' => 'provider_unavailable',
        ];
        $providerPrice = $result['price_minor'] ?? null;
        if (is_string($providerPrice) && ctype_digit($providerPrice)) {
            $providerPrice = (int) $providerPrice;
        }

        $providerCurrency = strtoupper(trim((string) ($result['currency'] ?? '')));
        $priceMinor = null;
        if ((bool) ($result['available'] ?? false)
            && is_int($providerPrice)
            && $providerPrice >= 0
            && preg_match('/^[A-Z]{3}$/', $providerCurrency) === 1
        ) {
            try {
                $priceMinor = $this->converter->convert($providerPrice, $providerCurrency, $product->currency);
            } catch (\Throwable) {
                $priceMinor = null;
            }
        }

        $available = (bool) ($result['available'] ?? false) && $priceMinor !== null;

        return [
            'domain' => $domain,
            'available' => $available,
            'price_minor' => $priceMinor,
            'currency' => $product->currency,
            'price' => $priceMinor === null ? null : MoneyFormatter::format($priceMinor, $product->currency),
            'reason' => $available ? ($result['reason'] ?? null) : ($result['reason'] ?? 'provider_price_unavailable'),
        ];
    }

    /** @return array<string, mixed> */
    private function configuration(Product $product): array
    {
        $capability = $product->capability('domain_registration');
        $config = $capability?->config;
        $config = is_array($config) ? $config : [];

        return [
            'registrar_key' => trim((string) ($config['registrar_key'] ?? '')) ?: 'demo-registrar',
            'dns_provider_key' => trim((string) ($config['dns_provider_key'] ?? '')) ?: 'demo-dns',
            'allowed_tlds' => $config['allowed_tlds'] ?? ['test', 'invalid'],
        ];
    }

    /** @param array<string, mixed> $config @return list<string> */
    private function allowedTlds(array $config): array
    {
        $tlds = is_array($config['allowed_tlds'] ?? null)
            ? $config['allowed_tlds']
            : preg_split('/[\s,]+/', (string) ($config['allowed_tlds'] ?? ''));

        $tlds = array_values(array_filter(array_map(static function (mixed $tld): ?string {
            $tld = strtolower(ltrim(trim((string) $tld), '.'));

            return preg_match('/^[a-z0-9-]{2,63}$/', $tld) === 1 ? $tld : null;
        }, $tlds)));

        return $tlds !== [] ? array_values(array_unique($tlds)) : ['test', 'invalid'];
    }
}
