<?php

declare(strict_types=1);

namespace Agovena\Modules\Domains\Http\Livewire\Storefront;

use Agovena\Modules\Domains\DomainSearchService;
use App\Agovena\Cart\CartService;
use App\Agovena\Theme\ThemeManager;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

final class DomainSearch extends Component
{
    public string $query = '';

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    public function search(DomainSearchService $domains): void
    {
        $this->resetValidation();
        $this->result = $domains->search($this->query);
        $this->attachTokens($domains);
    }

    public function selectDomain(string $token, DomainSearchService $domains, CartService $cart): void
    {
        $selection = $domains->selection($token);
        if ($selection === null) {
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

        return view($theme->view('domains.search'), [
            'theme' => $theme,
            'accountSection' => null,
        ])->layout($theme->view('layouts.storefront'), [
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
            $this->result['requested']['selection_token'] = $domains->issueSelection($this->result['requested']);
        }
        foreach (($this->result['alternatives'] ?? []) as $index => $alternative) {
            if (is_array($alternative)) {
                $alternative['selection_token'] = $domains->issueSelection($alternative);
                $this->result['alternatives'][$index] = $alternative;
            }
        }
    }
}
