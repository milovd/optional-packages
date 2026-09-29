<?php

declare(strict_types=1);

namespace Agovena\Modules\Domains\Listeners;

use Agovena\Modules\Domains\DomainName;
use Agovena\Modules\Domains\DomainSearchService;
use App\Events\OrderPreflight;
use Illuminate\Validation\ValidationException;

final class ValidateDomainOrderPreflight
{
    public function __construct(
        private readonly DomainSearchService $search,
    ) {}

    public function handle(OrderPreflight $event): void
    {
        foreach ($event->lines as $line) {
            $product = $this->search->domainProduct();
            if ($product === null || $product->id !== $line->productId) {
                continue;
            }

            $domain = null;
            foreach ($line->selections as $key => $value) {
                if (in_array(strtolower((string) $key), ['domain', 'domain_name'], true)) {
                    $domain = DomainName::normalize((string) $value);
                    break;
                }
            }

            if ($domain === null) {
                throw ValidationException::withMessages([
                    'cart' => __('domains::storefront.validation.selection_required'),
                ]);
            }

            $quoteExists = false;
            foreach ((array) session()->get('domains.quotes', []) as $quote) {
                if (is_array($quote) && ($quote['domain'] ?? null) === $domain && (int) ($quote['product_id'] ?? 0) === $product->id && (int) ($quote['expires_at'] ?? 0) >= now()->timestamp) {
                    $quoteExists = true;
                    break;
                }
            }

            if (! $quoteExists) {
                throw ValidationException::withMessages([
                    'cart' => __('domains::storefront.validation.search_again'),
                ]);
            }

            $result = $this->search->search($domain)['requested'];
            if (! ($result['available'] ?? false)) {
                throw ValidationException::withMessages([
                    'cart' => __('domains::storefront.validation.no_longer_available', ['domain' => $domain]),
                ]);
            }
        }
    }
}
