<div class="admin-page">
    <x-ag.page-header :heading="__('domains::admin.title')" :lede="__('domains::admin.lede')" />

    @if (session('status'))
        <p class="ag-alert ag-alert--success" role="status">{{ session('status') }}</p>
    @endif
    @if (session('error'))
        <p class="ag-alert ag-alert--danger" role="alert">{{ session('error') }}</p>
    @endif

    <div class="ag-toolbar" style="margin-bottom: 1rem;">
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
        <div class="ag-table-wrap">
            <table class="ag-table">
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
                        @endphp
                        <tr wire:key="domain-{{ $registration->id }}">
                            <td>{{ $registration->number }}</td>
                            <td>{{ $registration->domain_name ?? __('domains::admin.awaiting_domain') }}</td>
                            <td>{{ $registration->customer_email ?? $registration->customer?->email ?? '-' }}</td>
                            <td>{{ __('domains::admin.status.'.$registration->status->value) }}</td>
                            <td>
                                <strong>{{ $registrarKey ?? __('domains::admin.not_configured') }}</strong>
                                @if ($registrar)
                                    <small class="ag-muted">{{ implode(', ', $registrar->capabilities()) }}</small>
                                @endif
                            </td>
                            <td>
                                <strong>{{ $registration->dns_provider_key ?? __('domains::admin.not_configured') }}</strong>
                                @if ($dnsProvider)
                                    <small class="ag-muted">{{ implode(', ', $dnsProvider->capabilities()) }}</small>
                                @endif
                            </td>
                            @if ($canManage)
                                <td>
                                    <div class="ag-toolbar">
                                        @if (in_array($registration->status->value, ['pending', 'failed'], true) && $registration->domain_name)
                                            <button type="button" class="ag-btn ag-btn--secondary ag-btn--sm" wire:click="register({{ $registration->id }})" wire:loading.attr="disabled" wire:target="register({{ $registration->id }})">
                                                {{ __('domains::admin.register') }}
                                            </button>
                                        @endif
                                        @if (in_array($registration->status->value, ['active', 'renewal_due'], true))
                                            <button type="button" class="ag-btn ag-btn--secondary ag-btn--sm" wire:click="renew({{ $registration->id }})" wire:loading.attr="disabled" wire:target="renew({{ $registration->id }})">
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
