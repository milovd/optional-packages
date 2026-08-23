<?php

declare(strict_types=1);

namespace Agovena\Modules\Shipping;

use Agovena\Modules\Shipping\Models\ShippingMethod;
use App\Agovena\Cart\PricedCartLine;
use App\Agovena\Checkout\ShippingDestination;
use App\Agovena\Checkout\ShippingQuote;
use App\Agovena\Checkout\ShippingQuoteResolver;
use App\Agovena\Money\Money;
use App\Agovena\Shipping\Contracts\QuotesCartRates;
use App\Agovena\Shipping\ShippingCarrierRegistry;
use Throwable;

final class ModuleShippingQuoteResolver implements ShippingQuoteResolver
{
    public function __construct(
        private readonly ShippingRateCalculator $calculator,
        private readonly ShippingCarrierRegistry $carriers,
    ) {}

    public function quotes(array $lines, string $countryCode, string $currency, ?ShippingDestination $destination = null): array
    {
        if ($lines === []) {
            return [];
        }

        $quotes = $this->merchantQuotes($lines, $countryCode, $currency);

        if ($destination?->isComplete()) {
            $quotes = [...$quotes, ...$this->carrierQuotes($lines, $destination, $currency)];
        }

        return $quotes;
    }

    public function quote(array $lines, string $countryCode, string $currency, int $methodId, ?ShippingDestination $destination = null): ?ShippingQuote
    {
        foreach ($this->merchantQuotes($lines, $countryCode, $currency) as $quote) {
            if ($quote->methodId === $methodId) {
                return $quote;
            }
        }

        return null;
    }

    public function quoteByKey(array $lines, string $countryCode, string $currency, string $key, ?ShippingDestination $destination = null): ?ShippingQuote
    {
        foreach ($this->quotes($lines, $countryCode, $currency, $destination) as $quote) {
            if ($quote->key() === $key) {
                return $quote;
            }
        }

        return null;
    }

    /**
     * @param  list<PricedCartLine>  $lines
     * @return list<ShippingQuote>
     */
    private function merchantQuotes(array $lines, string $countryCode, string $currency): array
    {
        $methods = ShippingMethod::query()
            ->with('zone')
            ->where('is_active', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        $quotes = [];
        foreach ($methods as $method) {
            if (! $this->calculator->isEligible($method, $lines, $countryCode, $currency)) {
                continue;
            }
            $quotes[] = new ShippingQuote(
                methodId: $method->id,
                label: $method->name,
                amount: $this->calculator->amount($method, $lines, $currency),
            );
        }

        return $quotes;
    }

    /**
     * @param  list<PricedCartLine>  $lines
     * @return list<ShippingQuote>
     */
    private function carrierQuotes(array $lines, ShippingDestination $destination, string $currency): array
    {
        $quotes = [];

        foreach ($this->carriers->all() as $carrier) {
            if (! $carrier instanceof QuotesCartRates) {
                continue;
            }

            try {
                foreach ($carrier->quoteCart($lines, $destination, $currency) as $rate) {
                    $quotes[] = new ShippingQuote(
                        methodId: 0,
                        label: $rate->serviceLabel,
                        amount: Money::of($rate->amount, $rate->currency !== '' ? $rate->currency : $currency),
                        carrierId: $rate->carrierId,
                        serviceCode: $rate->serviceCode,
                    );
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $quotes;
    }
}
