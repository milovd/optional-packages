<div class="admin-page ag-list-page">
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

    <x-ag.table-toolbar filters>
        <div class="ag-toolbar__filters">
            <div class="ag-field ag-field--inline">
                <label class="visually-hidden" for="provisioning-status">{{ __('provisioning::admin.filter_status') }}</label>
                <select id="provisioning-status" class="ag-select" wire:model.live="status">
                    <option value="">{{ __('provisioning::admin.all_statuses') }}</option>
                    <option value="pending">{{ __('provisioning::status.pending') }}</option>
                    <option value="provisioning">{{ __('provisioning::status.provisioning') }}</option>
                    <option value="active">{{ __('provisioning::status.active') }}</option>
                    <option value="suspended">{{ __('provisioning::status.suspended') }}</option>
                    <option value="terminated">{{ __('provisioning::status.terminated') }}</option>
                    <option value="failed">{{ __('provisioning::status.failed') }}</option>
                    <option value="manual_review">{{ __('provisioning::status.manual_review') }}</option>
                </select>
            </div>
        </div>
    </x-ag.table-toolbar>

    @if ($instances->isEmpty())
        <div class="ag-empty" role="status">
            <p class="ag-empty__title">{{ __('provisioning::admin.empty') }}</p>
        </div>
    @else
        <div class="ag-table-wrap ag-table-wrap--mobile-cards" role="region" aria-label="{{ __('provisioning::admin.title') }}" tabindex="0" wire:loading.class="is-loading" wire:target="status">
            <table class="ag-table ag-table--mobile-cards">
                <thead>
                    <tr>
                        <th scope="col">{{ __('provisioning::admin.number') }}</th>
                        <th scope="col">{{ __('common.product') }}</th>
                        <th scope="col">{{ __('provisioning::admin.customer') }}</th>
                        <th scope="col">{{ __('provisioning::admin.status') }}</th>
                        <th scope="col">{{ __('provisioning::admin.external_ref') }}</th>
                        <th scope="col"><span class="visually-hidden">{{ __('common.actions') }}</span></th>
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
