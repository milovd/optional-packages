<section class="admin-panel">
    <h2 class="admin-panel__title">{{ __('provisioning::admin.title') }}</h2>
    <div class="ag-table-wrap">
        <table class="ag-table">
            <thead>
                <tr>
                    <th>{{ __('provisioning::admin.number') }}</th>
                    <th>{{ __('provisioning::admin.status') }}</th>
                    <th>{{ __('provisioning::admin.external_ref') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($instances as $instance)
                    <tr wire:key="cust-svc-{{ $instance->id }}">
                        <td>
                            <a href="{{ route('admin.provisioning.show', $instance) }}">{{ $instance->number }}</a>
                            @if ($instance->product)
                                <span class="ag-muted">{{ $instance->product->name }}</span>
                            @endif
                        </td>
                        <td>{{ __('provisioning::status.'.$instance->status->value) }}</td>
                        <td>{{ $instance->external_ref ?? '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3">{{ __('provisioning::admin.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
