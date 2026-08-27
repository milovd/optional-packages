<?php

declare(strict_types=1);

namespace Agovena\Modules\Domains\Http\Livewire\Customer;

use Agovena\Modules\Domains\Models\DomainRegistration;
use App\Agovena\Theme\ThemeManager;
use App\Models\Customer;
use Livewire\Component;

final class RegistrationsIndex extends Component
{
    public function render(ThemeManager $themes)
    {
        /** @var Customer $customer */
        $customer = authenticated_customer();
        $registrations = DomainRegistration::query()
            ->with('product')
            ->where('customer_id', $customer->id)
            ->orderByDesc('id')
            ->get();
        $theme = $themes->active();

        return view($theme->view('account.domains'), [
            'theme' => $theme,
            'registrations' => $registrations,
            'accountSection' => 'domains',
        ])->layout($theme->view('layouts.storefront'), [
            'title' => __('domains::customer.title'),
            'theme' => $theme,
        ]);
    }
}
