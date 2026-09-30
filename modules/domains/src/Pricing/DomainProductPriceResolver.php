<?php

declare(strict_types=1);

namespace Agovena\Modules\Domains\Pricing;

use Agovena\Modules\Domains\DomainSearchService;
use App\Agovena\Catalog\Pricing\Contracts\ProductPriceResolver;
use App\Agovena\Money\CurrencyConverter;
use App\Agovena\Money\Money;
use App\Models\Product;
use InvalidArgumentException;

final class DomainProductPriceResolver implements ProductPriceResolver
{
    public function __construct(
        private readonly DomainSearchService $domains,
        private readonly CurrencyConverter $converter,
    ) {}

    public function id(): string
    {
        return 'domains';
    }

    public function supports(Product $product): bool
    {
        return $product->hasCapability('domain_registration');
    }

    /** @param array<string, mixed> $selections */
    public function resolve(Product $product, array $selections, string $currency): Money
    {
        $domain = trim((string) ($selections['domain_name'] ?? ''));
        $quote = $this->domains->quoteFor($product, $domain);

        if ($quote === null) {
            throw new InvalidArgumentException('Domain selection is missing a valid server-side quote.');
        }

        return Money::of(
            $this->converter->convert((int) $quote['price_minor'], (string) $quote['currency'], $currency),
            $currency,
        );
    }
}
