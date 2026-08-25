<?php

declare(strict_types=1);

namespace Agovena\Modules\Shipping;

use Agovena\Modules\Shipping\Enums\ShippingMethodType;
use Agovena\Modules\Shipping\Models\ShippingMethod;
use App\Agovena\Cart\PricedCartLine;
use App\Agovena\Money\Money;
use App\Models\Product;

/**
 * Explicit, simple rate rules - not an enterprise rules engine.
 */
final class ShippingRateCalculator
{
    /**
     * @param list<PricedCartLine> $shippableLines
     */
    public function amount(ShippingMethod $method, array $shippableLines, string $currency): Money
    {
        $config = $method->configArray();
        $subtotal = $this->linesSubtotal($shippableLines, $currency);
        $weightGrams = $this->linesWeightGrams($shippableLines);

        return match ($method->type) {
            ShippingMethodType::Free => $this->free($config, $subtotal, $currency),
            ShippingMethodType::Flat => Money::of((int) ($config['amount'] ?? 0), $currency),
            ShippingMethodType::Price => $this->tiered($config['tiers'] ?? [], $subtotal->amount, $currency),
            ShippingMethodType::Weight => $this->tiered($config['tiers'] ?? [], $weightGrams, $currency),
            ShippingMethodType::Zone => Money::of((int) ($config['amount'] ?? 0), $currency),
        };
    }

    /**
     * @param list<PricedCartLine> $shippableLines
     */
    public function isEligible(ShippingMethod $method, array $shippableLines, string $countryCode, string $currency): bool
    {
        if (! $method->is_active) {
            return false;
        }

        if ($method->currency !== '' && strtoupper($method->currency) !== strtoupper($currency)) {
            return false;
        }

        $zone = $method->zone;
        if ($method->type === ShippingMethodType::Zone) {
            if ($zone === null || ! $zone->is_active || ! $zone->coversCountry($countryCode)) {
                return false;
            }
        } elseif ($zone !== null) {
            if (! $zone->is_active || ! $zone->coversCountry($countryCode)) {
                return false;
            }
        }

        $config = $method->configArray();
        $subtotal = $this->linesSubtotal($shippableLines, $currency);

        if (isset($config['min_subtotal']) && $subtotal->amount < (int) $config['min_subtotal']) {
            return false;
        }

        if (isset($config['max_subtotal']) && $subtotal->amount > (int) $config['max_subtotal']) {
            return false;
        }

        $weight = $this->linesWeightGrams($shippableLines);
        if (isset($config['min_weight_grams']) && $weight < (int) $config['min_weight_grams']) {
            return false;
        }

        if (isset($config['max_weight_grams']) && $weight > (int) $config['max_weight_grams']) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function free(array $config, Money $subtotal, string $currency): Money
    {
        $min = isset($config['min_subtotal']) ? (int) $config['min_subtotal'] : null;
        if ($min !== null && $subtotal->amount < $min) {
            // Still eligible as a method only when min met - amount unused if ineligible.
            return Money::of(0, $currency);
        }

        return Money::of(0, $currency);
    }

    private function tiered(mixed $tiers, int $value, string $currency): Money
    {
        if (! is_array($tiers)) {
            return Money::of(0, $currency);
        }

        foreach ($tiers as $tier) {
            if (! is_array($tier)) {
                continue;
            }
            $min = (int) ($tier['min'] ?? 0);
            $max = array_key_exists('max', $tier) && $tier['max'] !== null ? (int) $tier['max'] : null;
            if ($value < $min) {
                continue;
            }
            if ($max !== null && $value > $max) {
                continue;
            }

            return Money::of((int) ($tier['amount'] ?? 0), $currency);
        }

        return Money::of(0, $currency);
    }

    /**
     * @param list<PricedCartLine> $lines
     */
    private function linesSubtotal(array $lines, string $currency): Money
    {
        $total = Money::of(0, $currency);
        foreach ($lines as $line) {
            $total = $total->add($line->lineTotal);
        }

        return $total;
    }

    /**
     * @param list<PricedCartLine> $lines
     */
    private function linesWeightGrams(array $lines): int
    {
        $grams = 0;
        foreach ($lines as $line) {
            $product = Product::query()->with('capabilities')->find($line->productId);
            $weight = 0;
            if ($product !== null) {
                $row = $product->capability('shippable');
                if ($row !== null) {
                    $config = is_array($row->config) ? $row->config : [];
                    $weight = (int) ($config['weight_grams'] ?? 0);
                }
            }
            $grams += max(0, $weight) * $line->quantity;
        }

        return $grams;
    }
}
