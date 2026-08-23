<?php

declare(strict_types=1);

namespace Agovena\Modules\DigitalDelivery\Http\Livewire\Customer;

use Agovena\Modules\DigitalDelivery\DigitalSecretFulfillmentService;
use App\Agovena\Theme\ThemeManager;
use App\Models\Customer;
use Livewire\Component;

final class SecretsIndex extends Component
{
    public function render(ThemeManager $themes, DigitalSecretFulfillmentService $secrets)
    {
        /** @var Customer $customer */
        $customer = authenticated_customer();

        $deliveries = $secrets->deliveriesForCustomer($customer)
            ->with(['product', 'order'])
            ->orderByDesc('id')
            ->get();

        // Resolve plaintext here so the view never touches ciphertext.
        $values = [];
        foreach ($deliveries as $delivery) {
            if ($delivery->isReadableBy($customer)) {
                $values[$delivery->id] = $delivery->plainValue();
            }
        }

        $theme = $themes->active();

        return view($theme->view('account.digital-secrets'), [
            'theme' => $theme,
            'deliveries' => $deliveries,
            'values' => $values,
            'accountSection' => 'secrets',
        ])->layout($theme->view('layouts.storefront'), [
            'title' => __('digital-delivery::customer.title'),
            'theme' => $theme,
        ]);
    }
}
