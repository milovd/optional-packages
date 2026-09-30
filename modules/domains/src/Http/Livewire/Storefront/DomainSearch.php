<?php

declare(strict_types=1);

namespace Agovena\Modules\Domains\Http\Livewire\Storefront;

use Agovena\Modules\Domains\DomainSearchService;
use App\Agovena\Cart\CartService;
use App\Agovena\Catalog\GetStorefrontProduct;
use App\Agovena\Theme\ThemeManager;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class DomainSearch extends Component
{
    public string $query = '';

    #[Locked]
    public ?int $productId = null;

    public int $quantity = 1;

    public string $intent = 'cart';

    public ?string $slug = null;

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    public function mount(?int $productId = null, ?string $slug = null): void
    {
        $this->slug = $slug;
        if ($slug !== null) {
            $product = app(GetStorefrontProduct::class)->handle($slug);
            abort_unless($product->hasCapability('domain_registration'), 404);
            $productId = $product->id;
        }

        $this->productId = $productId;
        $this->quantity = min(99, max(1, (int) request()->query('quantity', 1)));
        $requestedIntent = request()->query('intent');
        $this->intent = $requestedIntent === 'checkout' || ($requestedIntent === null && $this->productId === null)
            ? 'checkout'
            : 'cart';
    }

    public function search(DomainSearchService $domains): void
    {
        $this->resetValidation();
        $this->result = $domains->search($this->query, $this->productId);
        $this->attachTokens($domains);
    }

    public function selectDomain(string $token, DomainSearchService $domains, CartService $cart): void
    {
        $selection = $domains->selection($token);
        if ($selection === null || ($this->productId !== null && (int) ($selection['product_id'] ?? 0) !== $this->productId)) {
            throw ValidationException::withMessages([
                'query' => __('domains::storefront.validation.search_again'),
            ]);
        }

        $quantity = min(99, max(1, (int) $this->quantity));
        $cart->add((int) $selection['product_id'], $quantity, [
            'domain_name' => (string) $selection['domain'],
            'dns_management' => true,
        ]);
        session()->flash('status', __('domains::storefront.added_to_checkout'));
        $target = $this->intent === 'checkout' ? 'storefront.checkout' : 'storefront.cart';
        $this->redirect(route($target), navigate: true);
    }

    public function render(ThemeManager $themes, DomainSearchService $domains)
    {
        $theme = $themes->active();
        $product = $this->productId !== null ? $domains->domainProduct($this->productId) : null;

        $view = view($theme->view('domains.search'), [
            'theme' => $theme,
            'accountSection' => null,
            'embedded' => false,
            'configuration' => $product !== null,
            'product' => $product,
        ]);

        return $view->layout($theme->view('layouts.storefront'), [
            'title' => $product !== null
                ? __('domains::storefront.configuration_title', ['product' => $product->name])
                : __('domains::storefront.title'),
            'theme' => $theme,
        ]);
    }

    private function attachTokens(DomainSearchService $domains): void
    {
        if (! is_array($this->result)) {
            return;
        }

        if (isset($this->result['requested']) && is_array($this->result['requested'])) {
            $this->result['requested']['selection_token'] = $domains->issueSelection($this->result['requested'], $this->productId);
        }
        foreach (($this->result['alternatives'] ?? []) as $index => $alternative) {
            if (is_array($alternative)) {
                $alternative['selection_token'] = $domains->issueSelection($alternative, $this->productId);
                $this->result['alternatives'][$index] = $alternative;
            }
        }
    }
}
