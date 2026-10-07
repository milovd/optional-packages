<div class="admin-page">
    <x-ag.page-header :heading="__('digital-delivery::admin.title')" :lede="__('digital-delivery::admin.lede')" />

    @if (session('status'))
        <p class="ag-alert ag-alert--success" role="status">{{ session('status') }}</p>
    @endif

    @can('digital_delivery.manage')
        <form wire:submit="addCodes" class="ag-form ag-section" style="margin-bottom: 1.5rem;">
            <header class="ag-section__header">
                <h3 class="ag-section__title">{{ __('digital-delivery::admin.add') }}</h3>
                <p class="ag-section__lede">{{ __('digital-delivery::admin.add_hint') }}</p>
            </header>
            <div class="ag-section__body">
                <div class="ag-grid ag-grid--2">
                    <div class="ag-field">
                        <label class="ag-field__label" for="ds-product">{{ __('common.product') }}</label>
                        <select id="ds-product" class="ag-select" wire:model="product_id" required>
                            <option value="">{{ __('common.none') }}</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}">{{ $product->name }}</option>
                            @endforeach
                        </select>
                        @error('product_id') <p class="ag-field__error">{{ $message }}</p> @enderror
                    </div>
                    <div class="ag-field">
                        <label class="ag-field__label" for="ds-label">{{ __('digital-delivery::admin.label') }}</label>
                        <input id="ds-label" class="ag-input" type="text" wire:model="label">
                        <p class="ag-field__hint">{{ __('digital-delivery::admin.label_hint') }}</p>
                    </div>
                </div>
                <div class="ag-field">
                    <label class="ag-field__label" for="ds-codes">{{ __('digital-delivery::admin.codes') }}</label>
                    <textarea id="ds-codes" class="ag-input" rows="6" wire:model="codes" spellcheck="false" autocomplete="off" required></textarea>
                    <p class="ag-field__hint">{{ __('digital-delivery::admin.codes_hint') }}</p>
                    @error('codes') <p class="ag-field__error">{{ $message }}</p> @enderror
                </div>
            </div>
            <button type="submit" class="ag-btn ag-btn--primary">{{ __('digital-delivery::admin.save') }}</button>
        </form>
    @endcan

    <section class="ag-section" style="margin-bottom: 1.5rem;">
        <header class="ag-section__header">
            <h3 class="ag-section__title">{{ __('digital-delivery::admin.pools') }}</h3>
        </header>
        @if ($products->isEmpty())
            <p class="ag-muted">{{ __('digital-delivery::admin.empty_products') }}</p>
        @else
            <div class="ag-table-wrap">
                <table class="ag-table">
                    <thead>
                        <tr>
                            <th>{{ __('common.product') }}</th>
                            <th>{{ __('digital-delivery::admin.available') }}</th>
                            <th>{{ __('digital-delivery::admin.allocated') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($products as $product)
                            <tr wire:key="pool-{{ $product->id }}">
                                <td>{{ $product->name }}</td>
                                <td>{{ $counts[$product->id]['available'] ?? 0 }}</td>
                                <td>{{ $counts[$product->id]['allocated'] ?? 0 }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="ag-section">
        <header class="ag-section__header">
            <h3 class="ag-section__title">{{ __('digital-delivery::admin.deliveries') }}</h3>
            <p class="ag-section__lede">{{ __('digital-delivery::admin.deliveries_hint') }}</p>
        </header>

        @if ($deliveries->isEmpty())
            <p class="ag-muted">{{ __('digital-delivery::admin.empty_deliveries') }}</p>
        @else
            <div class="ag-table-wrap">
                <table class="ag-table">
                    <thead>
                        <tr>
                            <th>{{ __('common.product') }}</th>
                            <th>{{ __('digital-delivery::admin.order') }}</th>
                            <th>{{ __('digital-delivery::admin.source') }}</th>
                            <th>{{ __('digital-delivery::admin.status') }}</th>
                            <th>{{ __('digital-delivery::admin.hint') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($deliveries as $delivery)
                            <tr wire:key="delivery-{{ $delivery->id }}">
                                <td>{{ $delivery->product?->name }}</td>
                                <td>{{ $delivery->order?->number }}</td>
                                <td>{{ __('digital-delivery::admin.sources.'.$delivery->source) }}</td>
                                <td>{{ __('digital-delivery::admin.statuses.'.$delivery->status) }}</td>
                                <td><code class="ag-code">{{ $delivery->value_hint ?? '-' }}</code></td>
                                <td>
                                    @can('digital_delivery.manage')
                                        @if ($delivery->isPendingManual())
                                            <button type="button" class="ag-btn ag-btn--secondary" wire:click="startAssign({{ $delivery->id }})">
                                                {{ __('digital-delivery::admin.assign') }}
                                            </button>
                                        @elseif ($delivery->isDelivered())
                                            <button type="button" class="ag-btn ag-btn--ghost" wire:click="revoke({{ $delivery->id }})">
                                                {{ __('digital-delivery::admin.revoke') }}
                                            </button>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                            @if ($assign_delivery_id === $delivery->id)
                                <tr wire:key="assign-{{ $delivery->id }}">
                                    <td colspan="6">
                                        <form wire:submit="assign" class="ag-form">
                                            <div class="ag-field">
                                                <label class="ag-field__label" for="ds-assign-{{ $delivery->id }}">
                                                    {{ __('digital-delivery::admin.assign_value') }}
                                                </label>
                                                <input
                                                    id="ds-assign-{{ $delivery->id }}"
                                                    class="ag-input"
                                                    type="text"
                                                    wire:model="assign_value"
                                                    autocomplete="off"
                                                    spellcheck="false"
                                                    required
                                                >
                                                <p class="ag-field__hint">{{ __('digital-delivery::admin.assign_hint') }}</p>
                                                @error('assign_value') <p class="ag-field__error">{{ $message }}</p> @enderror
                                            </div>
                                            <button type="submit" class="ag-btn ag-btn--primary">{{ __('digital-delivery::admin.assign_save') }}</button>
                                            <button type="button" class="ag-btn ag-btn--ghost" wire:click="cancelAssign">{{ __('common.cancel') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
