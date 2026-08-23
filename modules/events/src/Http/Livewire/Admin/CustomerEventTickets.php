<?php

declare(strict_types=1);

namespace Agovena\Modules\Events\Http\Livewire\Admin;

use Agovena\Modules\Events\Models\EventTicket;
use App\Models\Customer;
use Livewire\Component;

final class CustomerEventTickets extends Component
{
    public Customer $customer;

    public function render()
    {
        $tickets = EventTicket::query()
            ->with(['event', 'performance'])
            ->where('customer_id', $this->customer->id)
            ->latest('id')
            ->limit(8)
            ->get();

        return view('livewire.admin.events.customer-section', [
            'tickets' => $tickets,
        ]);
    }
}
