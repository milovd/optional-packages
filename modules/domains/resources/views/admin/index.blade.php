<div class="admin-page">
    <x-ag.page-header :heading="__('domains::admin.title')" :lede="__('domains::admin.lede')" />

    @if (session('status'))
        <p class="ag-alert ag-alert--success" role="status">{{ session('status') }}</p>
    @endif
    @if (session('error'))
        <p class="ag-alert ag-alert--danger" role="alert">{{ session('error') }}</p>
    @endif

    <div class="ag-toolbar ag-u-mb-4">
        <label class="sr-only" for="domain-status">{{ __('domains::admin.filter_status') }}</label>
        <select id="domain-status" class="ag-select" wire:model.live="status">
            <option value="">{{ __('domains::admin.all_statuses') }}</option>
            @foreach (['pending', 'checking', 'registering', 'active', 'renewal_due', 'transfer_pending', 'expired', 'failed', 'cancelled'] as $status)
                <option value="{{ $status }}">{{ __('domains::admin.status.'.$status) }}</option>
            @endforeach
        </select>
    </div>

    @if ($registrations->isEmpty())
        <p class="ag-muted">{{ __('domains::admin.empty') }}</p>
    @else
        <div class="ag-table-wrap ag-table-wrap--mobile-cards" role="region" aria-label="{{ __('domains::admin.title') }}" tabindex="0">
            <table class="ag-table ag-table--mobile-cards">
                <thead>
                    <tr>
                        <th>{{ __('domains::admin.number') }}</th>
                        <th>{{ __('domains::admin.domain') }}</th>
                        <th>{{ __('domains::admin.customer') }}</th>
                        <th>{{ __('domains::admin.status_label') }}</th>
                        <th>{{ __('domains::admin.registrar') }}</th>
                        <th>{{ __('domains::admin.dns_provider') }}</th>
                        @if ($canManage)
                            <th>{{ __('domains::admin.actions') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($registrations as $registration)
                        @php
                            $registrarKey = $registration->registrar_key ?? $registration->provider_key;
                            $registrar = $registrarKey !== null ? ($registrars[$registrarKey] ?? null) : null;
                            $dnsProvider = $registration->dns_provider_key !== null
                                ? ($dnsProviders[$registration->dns_provider_key] ?? null)
                                : null;
                            $hasDnsZone = is_array($registration->meta)
                                && is_array($registration->meta['dns_zone'] ?? null)
                                && filled($registration->meta['dns_zone']['zone_reference'] ?? null);
                            // The mirrored legacy Core view must also work with older installed Modules.
                            $registerConfirmation = \Illuminate\Support\Facades\Lang::has('domains::admin.register_confirm')
                                ? __('domains::admin.register_confirm', ['domain' => $registration->domain_name])
                                : __('common.confirm_external_action', ['action' => __('domains::admin.register'), 'name' => $registration->domain_name]);
                            $renewConfirmation = \Illuminate\Support\Facades\Lang::has('domains::admin.renew_confirm')
                                ? __('domains::admin.renew_confirm', ['domain' => $registration->domain_name])
                                : __('common.confirm_external_action', ['action' => __('domains::admin.renew'), 'name' => $registration->domain_name]);
                        @endphp
                        <tr wire:key="domain-{{ $registration->id }}">
                            <td data-label="{{ __('domains::admin.number') }}">{{ $registration->number }}</td>
                            <td data-label="{{ __('domains::admin.domain') }}">{{ $registration->domain_name ?? __('domains::admin.awaiting_domain') }}</td>
                            <td data-label="{{ __('domains::admin.customer') }}">{{ $registration->customer_email ?? $registration->customer?->email ?? '-' }}</td>
                            <td data-label="{{ __('domains::admin.status_label') }}"><x-ag.badge :variant="match ($registration->status->value) { 'active' => 'success', 'failed' => 'danger', 'renewal_due', 'expired' => 'warning', 'pending', 'checking', 'registering', 'transfer_pending' => 'info', default => 'muted' }">{{ __('domains::admin.status.'.$registration->status->value) }}</x-ag.badge></td>
                            <td data-label="{{ __('domains::admin.registrar') }}">
                                <div class="ag-table__primary">
                                    <strong>{{ $registrarKey ?? __('domains::admin.not_configured') }}</strong>
                                    @if ($registrar)
                                        <small class="ag-muted">{{ implode(', ', $registrar->capabilities()) }}</small>
                                    @endif
                                </div>
                            </td>
                            <td data-label="{{ __('domains::admin.dns_provider') }}">
                                <div class="ag-table__primary">
                                    <strong>{{ $registration->dns_provider_key ?? __('domains::admin.not_configured') }}</strong>
                                    @if ($dnsProvider)
                                        <small class="ag-muted">{{ implode(', ', $dnsProvider->capabilities()) }}</small>
                                    @endif
                                </div>
                            </td>
                            @if ($canManage)
                                <td class="ag-table__actions" data-label="{{ __('domains::admin.actions') }}">
                                    <div class="ag-toolbar">
                                        @if (in_array($registration->status->value, ['pending', 'failed'], true) && $registration->domain_name)
                                            <button type="button" class="ag-btn ag-btn--secondary ag-btn--sm" wire:click="register({{ $registration->id }})" wire:confirm="{{ $registerConfirmation }}" wire:loading.attr="disabled" wire:target="register({{ $registration->id }})">
                                                {{ __('domains::admin.register') }}
                                            </button>
                                        @endif
                                        @if (in_array($registration->status->value, ['active', 'renewal_due'], true))
                                            <button type="button" class="ag-btn ag-btn--secondary ag-btn--sm" wire:click="renew({{ $registration->id }})" wire:confirm="{{ $renewConfirmation }}" wire:loading.attr="disabled" wire:target="renew({{ $registration->id }})">
                                                {{ __('domains::admin.renew') }}
                                            </button>
                                        @endif
                                        @if ($registration->dns_provider_key && ! $hasDnsZone)
                                            <button type="button" class="ag-btn ag-btn--secondary ag-btn--sm" wire:click="ensureDnsZone({{ $registration->id }})" wire:loading.attr="disabled" wire:target="ensureDnsZone({{ $registration->id }})">
                                                {{ __('domains::admin.ensure_dns_zone') }}
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
