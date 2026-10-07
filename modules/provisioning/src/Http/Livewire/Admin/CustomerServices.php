<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning\Http\Livewire\Admin;

use Agovena\Modules\Provisioning\Models\ServiceInstance;
use App\Models\Customer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

final class CustomerServices extends Component
{
    use AuthorizesRequests;

    public Customer $customer;

    public function mount(Customer $customer): void
    {
        $this->authorize('provisioning.view');
        $this->customer = $customer;
    }

    public function render()
    {
        $this->authorize('provisioning.view');

        $instances = ServiceInstance::query()
            ->with('product')
            ->where('customer_id', $this->customer->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return view('provisioning::admin.customer-section', [
            'instances' => $instances,
        ]);
    }
}
