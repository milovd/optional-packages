<?php

declare(strict_types=1);

namespace Agovena\Modules\Events\Http\Livewire\Customer;

use Agovena\Modules\Events\Models\EventTicket;
use App\Agovena\Theme\ThemeManager;
use App\Models\Customer;
use Livewire\Component;

final class TicketShow extends Component
{
    public EventTicket $ticket;

    public function mount(EventTicket $ticket): void
    {
        /** @var Customer $customer */
        $customer = authenticated_customer();
        abort_unless(
            (int) $ticket->customer_id === (int) $customer->id || $ticket->customer_email === $customer->email,
            404,
        );
        abort_if($ticket->status->value === 'void', 404);
        $this->ticket = $ticket->load(['event', 'performance', 'ticketType']);
    }

    public function render(ThemeManager $themes)
    {
        $theme = $themes->active();

        return view($theme->view('account.event-tickets.show'), [
            'theme' => $theme,
            'ticket' => $this->ticket,
            'accountSection' => 'event-tickets',
        ])->layout($theme->view('layouts.storefront'), [
            'title' => $this->ticket->number,
            'theme' => $theme,
        ]);
    }
}
