<section class="admin-panel">
    <h2 class="admin-panel__title">{{ __('events::admin.customer_heading') }}</h2>
    @if ($tickets->isEmpty())
        <p class="ag-muted">{{ __('events::admin.no_customer_tickets') }}</p>
    @else
        <div class="ag-table-wrap">
            <table class="ag-table">
                <thead>
                    <tr>
                        <th>{{ __('events::admin.ticket_number') }}</th>
                        <th>{{ __('events::admin.name') }}</th>
                        <th>{{ __('common.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($tickets as $ticket)
                        <tr wire:key="customer-ticket-{{ $ticket->id }}">
                            <td>{{ $ticket->number }}</td>
                            <td>{{ $ticket->event?->name }}</td>
                            <td>{{ __('events::admin.ticket_status.'.$ticket->status->value) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
