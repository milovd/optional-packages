<?php

declare(strict_types=1);

namespace Agovena\Modules\Subscriptions\Http\Livewire\Admin;

use Agovena\Modules\Subscriptions\Models\Subscription;
use App\Models\Customer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

final class CustomerSubscriptions extends Component
{
    use AuthorizesRequests;

    public Customer $customer;

    public function mount(Customer $customer): void
    {
        $this->authorize('subscriptions.view');
        $this->customer = $customer;
    }

    public function render()
    {
        $subscriptions = Subscription::query()
            ->with('product')
            ->where('customer_id', $this->customer->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return view('livewire.admin.subscriptions.customer-section', [
            'subscriptions' => $subscriptions,
        ]);
    }
}
