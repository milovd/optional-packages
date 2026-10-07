<?php

declare(strict_types=1);

namespace Agovena\Modules\Events\Http\Livewire\Admin;

use Agovena\Modules\Events\Models\EventTicket;
use App\Models\Customer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

final class CustomerEventTickets extends Component
{
    use AuthorizesRequests;

    public Customer $customer;

    public function render()
    {
        $this->authorize('events.view');

        $tickets = EventTicket::query()
            ->with(['event', 'performance'])
            ->where('customer_id', $this->customer->id)
            ->latest('id')
            ->limit(8)
            ->get();

        return view('events::admin.customer-section', [
            'tickets' => $tickets,
        ]);
    }
}
