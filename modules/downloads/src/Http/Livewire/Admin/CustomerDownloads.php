<?php

declare(strict_types=1);

namespace Agovena\Modules\Digital\Http\Livewire\Admin;

use Agovena\Modules\Digital\Models\DigitalEntitlement;
use App\Models\Customer;
use Livewire\Component;

final class CustomerDownloads extends Component
{
    public Customer $customer;

    public function render()
    {
        $entitlements = DigitalEntitlement::query()
            ->with('asset')
            ->where('customer_id', $this->customer->id)
            ->whereNull('revoked_at')
            ->latest('id')
            ->limit(8)
            ->get();

        return view('livewire.admin.digital.customer-section', [
            'entitlements' => $entitlements,
        ]);
    }
}
