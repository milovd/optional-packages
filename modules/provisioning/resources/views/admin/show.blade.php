<div class="admin-page">
    <x-ag.page-header
        :heading="__('provisioning::admin.show_title', ['number' => $instance->number])"
        :lede="$instance->product?->name"
    />

    @if (session('status'))
        <p class="ag-alert ag-alert--success" role="status">{{ session('status') }}</p>
    @endif
    @if (session('error'))
        <p class="ag-alert ag-alert--danger" role="alert">{{ session('error') }}</p>
    @endif

    <section class="ag-section">
        <header class="ag-section__header">
            <h3 class="ag-section__title">{{ __('provisioning::admin.details') }}</h3>
        </header>
        <div class="ag-section__body ag-grid ag-grid--2">
            <p><strong>{{ __('provisioning::admin.status') }}:</strong> {{ __('provisioning::status.'.$instance->status->value) }}</p>
            <p><strong>{{ __('provisioning::admin.customer') }}:</strong> {{ $instance->customer_email }}</p>
            @if ($instance->order)
                <p><strong>{{ __('provisioning::admin.order') }}:</strong>
                    <a href="{{ route('admin.orders.show', $instance->order) }}">{{ $instance->order->number }}</a>
                </p>
            @endif
            @if ($providerLabel)
                <p><strong>{{ __('provisioning::admin.provider') }}:</strong> {{ $providerLabel }}</p>
            @endif
            @if ($instance->external_ref)
                <p><strong>{{ __('provisioning::admin.external_ref') }}:</strong> {{ $instance->external_ref }}</p>
            @endif
            @if ($instance->failure_message)
                <p><strong>{{ __('provisioning::admin.failure') }}:</strong> {{ $instance->failure_message }}</p>
            @endif
        </div>
    </section>

    @if ($providerSettings !== [])
        <section class="ag-section">
            <header class="ag-section__header">
                <h3 class="ag-section__title">{{ __('provisioning::admin.configuration') }}</h3>
            </header>
            <div class="ag-section__body ag-grid ag-grid--2">
                @foreach ($providerSettings as $settingKey => $settingValue)
                    @if ($settingValue !== null && $settingValue !== '')
                        <p><strong>{{ $settingKey }}:</strong> {{ is_scalar($settingValue) ? $settingValue : json_encode($settingValue) }}</p>
                    @endif
                @endforeach
            </div>
        </section>
    @endif

    @can('provisioning.manage')
        <section class="ag-section">
            <header class="ag-section__header">
                <h3 class="ag-section__title">{{ __('provisioning::admin.tracking') }}</h3>
            </header>
            <form wire:submit="saveTracking" class="ag-section__body ag-grid ag-grid--2">
                <div class="ag-field">
                    <label class="ag-field__label" for="provider_key">{{ __('provisioning::admin.provider_key') }}</label>
                    <input id="provider_key" class="ag-input" type="text" wire:model="provider_key">
                    <p class="ag-field__hint">{{ __('provisioning::admin.provider_key_hint') }}</p>
                </div>
                <div class="ag-field">
                    <label class="ag-field__label" for="external_ref">{{ __('provisioning::admin.external_ref') }}</label>
                    <input id="external_ref" class="ag-input" type="text" wire:model="external_ref">
                </div>
                <div>
                    <button type="submit" class="ag-btn ag-btn--secondary">{{ __('provisioning::admin.save_tracking') }}</button>
                </div>
            </form>
        </section>

        <section class="ag-section">
            <header class="ag-section__header">
                <h3 class="ag-section__title">{{ __('provisioning::admin.actions') }}</h3>
            </header>
            <div class="ag-section__body" style="display:flex; gap:.75rem; flex-wrap:wrap;">
                @if ($usesLifecycle)
                    @if ($instance->status->value === 'failed')
                        <button type="button" class="ag-btn ag-btn--secondary" wire:click="markManualReview">
                            {{ __('provisioning::admin.mark_manual_review') }}
                        </button>
                    @endif
                    @if (in_array($instance->status->value, ['pending', 'failed'], true))
                        <button type="button" class="ag-btn ag-btn--primary" wire:click="retryProvisioning">
                            {{ __('provisioning::admin.retry_provisioning') }}
                        </button>
                    @endif
                    @if (in_array($instance->status->value, ['provisioning', 'active', 'suspended', 'failed'], true))
                        <button type="button" class="ag-btn ag-btn--secondary" wire:click="syncStatus">
                            {{ __('provisioning::admin.sync_status') }}
                        </button>
                    @endif
                @else
                    @if ($instance->status->value === 'failed')
                        <button type="button" class="ag-btn ag-btn--secondary" wire:click="markManualReview">
                            {{ __('provisioning::admin.mark_manual_review') }}
                        </button>
                    @endif
                    @if (in_array($instance->status->value, ['pending', 'failed', 'manual_review'], true))
                        <button type="button" class="ag-btn ag-btn--secondary" wire:click="markProvisioning">
                            {{ __('provisioning::admin.mark_provisioning') }}
                        </button>
                    @endif
                    @if ($instance->canActivate())
                        <button type="button" class="ag-btn ag-btn--primary" wire:click="activate">
                            {{ __('provisioning::admin.activate') }}
                        </button>
                    @endif
                @endif
                @if ($instance->canSuspend())
                    <button type="button" class="ag-btn ag-btn--secondary" wire:click="suspend" wire:confirm="{{ __('provisioning::admin.suspend_confirm') }}">
                        {{ __('provisioning::admin.suspend') }}
                    </button>
                @endif
                @if ($instance->status->value === 'suspended')
                    <button type="button" class="ag-btn ag-btn--secondary" wire:click="unsuspend" wire:confirm="{{ __('provisioning::admin.unsuspend_confirm') }}">
                        {{ __('provisioning::admin.unsuspend') }}
                    </button>
                @endif
                @if ($instance->canTerminate())
                    <button type="button" class="ag-btn ag-btn--danger" wire:click="terminate" wire:confirm="{{ __('provisioning::admin.terminate_confirm') }}">
                        {{ __('provisioning::admin.terminate') }}
                    </button>
                @endif
            </div>
        </section>
    @endcan

    <p><a href="{{ route('admin.provisioning.index') }}">{{ __('provisioning::admin.back') }}</a></p>
    @include('livewire.admin.partials.confirm-password-modal')
</div>
