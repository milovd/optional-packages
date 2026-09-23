<?php

declare(strict_types=1);

namespace Agovena\Extensions\Paddle;

use App\Agovena\Theme\ThemeManager;
use Livewire\Component;

final class PaddleCheckoutPage extends Component
{
    public ?string $clientToken = null;

    public bool $sandbox = true;

    public function mount(PaddlePaymentGateway $gateway): void
    {
        $this->clientToken = $gateway->clientSideToken();
        $this->sandbox = $gateway->isSandbox();
    }

    public function render(ThemeManager $themes)
    {
        $theme = $themes->active();

        return view('paddle::checkout', [
            'theme' => $theme,
            'clientToken' => $this->clientToken,
            'sandbox' => $this->sandbox,
        ])->layout($theme->view('layouts.storefront'), [
            'title' => __('paddle::messages.checkout.title'),
            'theme' => $theme,
        ]);
    }
}
