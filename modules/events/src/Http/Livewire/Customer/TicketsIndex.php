<?php

declare(strict_types=1);

namespace Agovena\Modules\Events\Http\Livewire\Customer;

use Agovena\Modules\Events\Models\EventTicket;
use App\Agovena\Theme\ThemeManager;
use App\Models\Customer;
use Livewire\Component;

final class TicketsIndex extends Component
{
    public function render(ThemeManager $themes)
    {
        /** @var Customer $customer */
        $customer = authenticated_customer();
        $tickets = EventTicket::query()
            ->with(['event', 'performance', 'ticketType'])
            ->where(function ($query) use ($customer): void {
                $query->where('customer_id', $customer->id)
                    ->orWhere('customer_email', $customer->email);
            })
            ->where('status', '!=', 'void')
            ->latest('id')
            ->get();

        $theme = $themes->active();

        return view($theme->view('account.event-tickets'), [
            'theme' => $theme,
            'tickets' => $tickets,
            'accountSection' => 'event-tickets',
        ])->layout($theme->view('layouts.storefront'), [
            'title' => __('events::customer.title'),
            'theme' => $theme,
        ]);
    }
}
