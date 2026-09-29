<?php

declare(strict_types=1);

namespace Agovena\Modules\Domains\Http\Livewire\Storefront;

use Agovena\Modules\Domains\DomainSearchService;
use App\Agovena\Cart\CartService;
use App\Agovena\Theme\ThemeManager;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class DomainSearch extends Component
{
    public string $query = '';

    #[Locked]
    public ?int $productId = null;

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    public function mount(?int $productId = null): void
    {
        $this->productId = $productId;
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

        $cart->add((int) $selection['product_id'], 1, [
            'domain_name' => (string) $selection['domain'],
            'dns_management' => true,
        ]);
        session()->flash('status', __('domains::storefront.added_to_checkout'));
        $this->redirect(route('storefront.checkout'), navigate: true);
    }

    public function render(ThemeManager $themes)
    {
        $theme = $themes->active();

        $view = view($theme->view('domains.search'), [
            'theme' => $theme,
            'accountSection' => null,
            'embedded' => $this->productId !== null,
        ]);

        if ($this->productId !== null) {
            return $view;
        }

        return $view->layout($theme->view('layouts.storefront'), [
            'title' => __('domains::storefront.title'),
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
