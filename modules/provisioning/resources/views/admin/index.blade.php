<div class="admin-page">
    <x-ag.page-header :heading="__('provisioning::admin.title')" :lede="__('provisioning::admin.lede')">
        @can('provisioning.manage')
            <x-slot:actions>
                <a class="ag-btn ag-btn--secondary" href="{{ route('admin.provisioning.servers') }}">
                    <x-ag.icon name="server" :size="16" />
                    {{ __('provisioning::admin.servers_title') }}
                </a>
            </x-slot:actions>
        @endcan
    </x-ag.page-header>

    <div class="ag-toolbar" style="margin-bottom: 1rem;">
        <select class="ag-select" wire:model.live="status" aria-label="{{ __('provisioning::admin.filter_status') }}">
            <option value="">{{ __('provisioning::admin.all_statuses') }}</option>
            <option value="pending">{{ __('provisioning::status.pending') }}</option>
            <option value="provisioning">{{ __('provisioning::status.provisioning') }}</option>
            <option value="active">{{ __('provisioning::status.active') }}</option>
            <option value="suspended">{{ __('provisioning::status.suspended') }}</option>
            <option value="terminated">{{ __('provisioning::status.terminated') }}</option>
            <option value="failed">{{ __('provisioning::status.failed') }}</option>
        </select>
    </div>

    @if ($instances->isEmpty())
        <p class="ag-muted">{{ __('provisioning::admin.empty') }}</p>
    @else
        <div class="ag-table-wrap ag-table-wrap--mobile-cards" role="region" aria-label="{{ __('provisioning::admin.title') }}" tabindex="0">
            <table class="ag-table ag-table--mobile-cards">
                <thead>
                    <tr>
                        <th>{{ __('provisioning::admin.number') }}</th>
                        <th>{{ __('common.product') }}</th>
                        <th>{{ __('provisioning::admin.customer') }}</th>
                        <th>{{ __('provisioning::admin.status') }}</th>
                        <th>{{ __('provisioning::admin.external_ref') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($instances as $instance)
                        <tr wire:key="svc-{{ $instance->id }}">
                            <td data-label="{{ __('provisioning::admin.number') }}"><a class="ag-table__name" href="{{ route('admin.provisioning.show', $instance) }}">{{ $instance->number }}</a></td>
                            <td data-label="{{ __('common.product') }}">{{ $instance->product?->name ?? '-' }}</td>
                            <td data-label="{{ __('provisioning::admin.customer') }}">{{ $instance->customer_email ?? '-' }}</td>
                            <td data-label="{{ __('provisioning::admin.status') }}"><x-ag.badge :variant="match ($instance->status->value) { 'active' => 'success', 'failed' => 'danger', 'suspended', 'manual_review' => 'warning', 'pending', 'provisioning' => 'info', default => 'muted' }">{{ __('provisioning::status.'.$instance->status->value) }}</x-ag.badge></td>
                            <td data-label="{{ __('provisioning::admin.external_ref') }}">{{ $instance->external_ref ?? '-' }}</td>
                            <td class="ag-table__actions" data-label="{{ __('common.actions') }}">
                                <a class="ag-icon-btn" href="{{ route('admin.provisioning.show', $instance) }}" title="{{ __('provisioning::admin.details') }}" aria-label="{{ __('provisioning::admin.details') }} {{ $instance->number }}">
                                    <x-ag.icon name="eye" :size="16" />
                                    <span class="ag-table__action-label">{{ __('provisioning::admin.details') }}</span>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
