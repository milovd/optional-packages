<div class="admin-page">
    <x-ag.page-header :heading="__('provisioning::admin.servers_title')" :lede="__('provisioning::admin.servers_lede')">
        <x-slot:actions>
            <button type="button" class="ag-btn ag-btn--primary" wire:click="create">{{ __('provisioning::admin.add_server') }}</button>
        </x-slot:actions>
    </x-ag.page-header>

    <div class="ag-grid ag-grid--2">
        <section class="ag-section">
            <header class="ag-section__header"><h3 class="ag-section__title">{{ __('provisioning::admin.configured_servers') }}</h3></header>
            <div class="ag-section__body">
                @forelse ($servers as $server)
                    <button type="button" class="ag-list__row ag-list__row--button" wire:click="edit({{ $server->id }})">
                        <span><strong>{{ $server->name }}</strong><small class="ag-muted">{{ $server->provider_key }}</small></span>
                        <span class="ag-badge {{ $server->is_active ? 'ag-badge--success' : 'ag-badge--muted' }}">{{ $server->is_active ? __('common.active') : __('common.inactive') }}</span>
                    </button>
                @empty
                    <p class="ag-muted">{{ __('provisioning::admin.no_servers') }}</p>
                @endforelse
            </div>
        </section>

        <section class="ag-section">
            <header class="ag-section__header"><h3 class="ag-section__title">{{ $editingId ? __('provisioning::admin.edit_server') : __('provisioning::admin.add_server') }}</h3></header>
            <form class="ag-section__body ag-form" wire:submit="save">
                <div class="ag-field">
                    <label class="ag-field__label" for="server-name">{{ __('common.name') }}</label>
                    <input id="server-name" class="ag-input" wire:model="name" required>
                    @error('name') <p class="ag-field__error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="ag-field">
                    <label class="ag-field__label" for="server-provider">{{ __('provisioning::admin.server_provider') }}</label>
                    <select id="server-provider" class="ag-select" wire:model.live="providerKey" @disabled($editingId !== null)>
                        @foreach ($providers as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                    </select>
                    @error('providerKey') <p class="ag-field__error" role="alert">{{ $message }}</p> @enderror
                </div>
                @foreach ($settingDefinitions as $definition)
                    <div class="ag-field">
                        <label class="ag-field__label" for="server-setting-{{ $definition->key }}">{{ __($definition->label) }}</label>
                        @if ($definition->type === 'boolean')
                            <x-ag.switch :id="'server-setting-'.$definition->key" wire:model="settings.{{ $definition->key }}" :label="__($definition->label)" />
                        @else
                            <input id="server-setting-{{ $definition->key }}" class="ag-input" type="{{ $definition->secret ? 'password' : 'text' }}" wire:model="settings.{{ $definition->key }}" @required($definition->required && ! $editingId) autocomplete="off">
                        @endif
                        @if ($definition->help !== '')<p class="ag-field__hint">{{ __($definition->help) }}</p>@endif
                        @error('settings.'.$definition->key) <p class="ag-field__error" role="alert">{{ $message }}</p> @enderror
                    </div>
                @endforeach
                <x-ag.switch id="server-active" wire:model="isActive" :label="__('common.active')" />
                <div class="ag-form__actions">
                    <button type="submit" class="ag-btn ag-btn--primary">{{ __('common.save') }}</button>
                    <button type="button" class="ag-btn ag-btn--secondary" wire:click="testConnection">{{ __('provisioning::admin.test_connection') }}</button>
                </div>
            </form>
        </section>
    </div>
</div>
