<section class="admin-panel">
    <h2 class="admin-panel__title">{{ __('digital-delivery::admin.customer_heading') }}</h2>
    @if ($deliveries->isEmpty())
        <p class="ag-muted">{{ __('digital-delivery::admin.customer_empty') }}</p>
    @else
        <div class="ag-table-wrap">
            <table class="ag-table">
                <thead>
                    <tr>
                        <th>{{ __('common.product') }}</th>
                        <th>{{ __('digital-delivery::admin.status') }}</th>
                        <th>{{ __('digital-delivery::admin.hint') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($deliveries as $delivery)
                        <tr wire:key="customer-secret-{{ $delivery->id }}">
                            <td>{{ $delivery->product?->name }}</td>
                            <td>{{ __('digital-delivery::admin.statuses.'.$delivery->status) }}</td>
                            <td>
                                @if ($revealedId === $delivery->id && $revealedValue !== null)
                                    <code class="ag-code">{{ $revealedValue }}</code>
                                @else
                                    <code class="ag-code">{{ $delivery->value_hint ?? '-' }}</code>
                                @endif
                            </td>
                            <td>
                                @can('digital_delivery.manage')
                                    @if ($delivery->isDelivered())
                                        @if ($revealedId === $delivery->id)
                                            <button type="button" class="ag-btn ag-btn--ghost" wire:click="hide">
                                                {{ __('digital-delivery::admin.conceal') }}
                                            </button>
                                        @else
                                            <button type="button" class="ag-btn ag-btn--ghost" wire:click="reveal({{ $delivery->id }})">
                                                {{ __('digital-delivery::admin.reveal') }}
                                            </button>
                                        @endif
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="ag-muted">{{ __('digital-delivery::admin.reveal_hint') }}</p>
    @endif
</section>
