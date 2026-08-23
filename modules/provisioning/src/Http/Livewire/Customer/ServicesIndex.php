<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning\Http\Livewire\Customer;

use Agovena\Modules\Provisioning\Models\ServiceInstance;
use App\Agovena\Theme\ThemeManager;
use App\Models\Customer;
use Livewire\Component;

final class ServicesIndex extends Component
{
    public function render(ThemeManager $themes)
    {
        /** @var Customer $customer */
        $customer = authenticated_customer();

        $instances = ServiceInstance::query()
            ->with('product')
            ->where(function ($q) use ($customer): void {
                $q->where('customer_id', $customer->id)
                    ->orWhere('customer_email', $customer->email);
            })
            ->orderByDesc('id')
            ->get();

        $theme = $themes->active();

        return view($theme->view('account.services'), [
            'theme' => $theme,
            'instances' => $instances,
            'accountSection' => 'services',
        ])->layout($theme->view('layouts.storefront'), [
            'title' => __('provisioning::customer.title'),
            'theme' => $theme,
        ]);
    }
}
