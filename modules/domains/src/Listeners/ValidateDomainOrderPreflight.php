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
            $product = $this->search->domainProduct($line->productId);
            if ($product === null) {
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

            $quote = $this->search->quoteFor($product, $domain);
            if ($quote === null) {
                throw ValidationException::withMessages([
                    'cart' => __('domains::storefront.validation.search_again'),
                ]);
            }

            $result = $this->search->search($domain, $product->id)['requested'];
            if (! ($result['available'] ?? false)) {
                throw ValidationException::withMessages([
                    'cart' => __('domains::storefront.validation.no_longer_available', ['domain' => $domain]),
                ]);
            }

            if ((int) ($result['price_minor'] ?? -1) !== (int) $quote['price_minor']
                || (string) ($result['currency'] ?? '') !== (string) $quote['currency']
            ) {
                throw ValidationException::withMessages([
                    'cart' => __('domains::storefront.validation.price_changed', ['domain' => $domain]),
                ]);
            }
        }
    }
}
