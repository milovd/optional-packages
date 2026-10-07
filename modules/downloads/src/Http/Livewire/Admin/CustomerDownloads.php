<?php

declare(strict_types=1);

namespace Agovena\Modules\Digital\Http\Livewire\Admin;

use Agovena\Modules\Digital\Models\DigitalEntitlement;
use App\Models\Customer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

final class CustomerDownloads extends Component
{
    use AuthorizesRequests;

    public Customer $customer;

    public function render()
    {
        $this->authorize('digital.view');

        $entitlements = DigitalEntitlement::query()
            ->with('asset')
            ->where('customer_id', $this->customer->id)
            ->whereNull('revoked_at')
            ->latest('id')
            ->limit(8)
            ->get();

        return view('digital::admin.customer-section', [
            'entitlements' => $entitlements,
        ]);
    }
}
