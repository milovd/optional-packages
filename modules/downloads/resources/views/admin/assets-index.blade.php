<div class="admin-page ag-list-page">
    <x-ag.page-header :heading="__('digital::admin.title')" :lede="__('digital::admin.lede')" />

    @if (session('status'))
        <p class="ag-alert ag-alert--success" role="status">{{ session('status') }}</p>
    @endif

    @can('digital.manage')
        <form wire:submit="save" class="ag-card ag-form" aria-labelledby="digital-add-heading" enctype="multipart/form-data">
            <header class="ag-card__header">
                <x-ag.icon-tile name="upload" tone="blue" :size="16" />
                <h2 id="digital-add-heading" class="ag-card__title">{{ __('digital::admin.add') }}</h2>
            </header>
            <div class="ag-card__content ag-grid ag-grid--2">
                <div class="ag-field">
                    <label class="ag-field__label" for="d-product">{{ __('common.product') }}</label>
                    <select id="d-product" class="ag-select" wire:model="product_id" required>
                        <option value="">{{ __('common.none') }}</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}">{{ $product->name }}</option>
                        @endforeach
                    </select>
                    @error('product_id') <p class="ag-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="ag-field">
                    <label class="ag-field__label" for="d-label">{{ __('digital::admin.label') }}</label>
                    <input id="d-label" class="ag-input" type="text" wire:model="label" required>
                    @error('label') <p class="ag-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="ag-field">
                    <label class="ag-field__label" for="d-limit">{{ __('digital::admin.download_limit') }}</label>
                    <input id="d-limit" class="ag-input" type="number" min="1" wire:model="download_limit">
                    <p class="ag-field__hint">{{ __('digital::admin.download_limit_hint') }}</p>
                    @error('download_limit') <p class="ag-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="ag-field">
                    <label class="ag-field__label" for="d-file">{{ __('digital::admin.file') }}</label>
                    <input id="d-file" class="ag-input" type="file" wire:model="file" required>
                    @error('file') <p class="ag-field__error">{{ $message }}</p> @enderror
                </div>
            </div>
            <footer class="ag-card__footer">
                <button type="submit" class="ag-btn ag-btn--primary" wire:loading.attr="disabled" wire:target="save,file">{{ __('digital::admin.save') }}</button>
            </footer>
        </form>
    @endcan

    @if ($assets->isEmpty())
        <div class="ag-empty" role="status">
            <p class="ag-empty__title">{{ __('digital::admin.empty') }}</p>
        </div>
    @else
        <div class="ag-table-wrap ag-table-wrap--mobile-cards" role="region" aria-label="{{ __('digital::admin.title') }}" tabindex="0">
            <table class="ag-table ag-table--mobile-cards">
                <thead>
                    <tr>
                        <th scope="col">{{ __('common.product') }}</th>
                        <th scope="col">{{ __('digital::admin.label') }}</th>
                        <th scope="col">{{ __('digital::admin.filename') }}</th>
                        <th scope="col">{{ __('digital::admin.download_limit') }}</th>
                        @can('digital.manage')
                            <th scope="col">{{ __('common.actions') }}</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @foreach ($assets as $asset)
                        <tr wire:key="asset-{{ $asset->id }}">
                            <td data-label="{{ __('common.product') }}">{{ $asset->product?->name }}</td>
                            <td data-label="{{ __('digital::admin.label') }}">{{ $asset->label }}</td>
                            <td data-label="{{ __('digital::admin.filename') }}">{{ $asset->filename }}</td>
                            <td data-label="{{ __('digital::admin.download_limit') }}">{{ $asset->download_limit ?? '∞' }}</td>
                            @can('digital.manage')
                                <td class="ag-table__actions" data-label="{{ __('common.actions') }}">
                                    <button type="button" class="ag-btn ag-btn--ghost" aria-label="{{ __('common.delete') }} {{ $asset->filename }}" wire:click="$wire.call('delete', {{ $asset->id }})" wire:confirm="{{ \Illuminate\Support\Facades\Lang::has('digital::admin.delete_confirm') ? __('digital::admin.delete_confirm', ['name' => $asset->filename]) : __('common.confirm_irreversible_delete', ['name' => $asset->filename]) }}">{{ __('common.delete') }}</button>
                                </td>
                            @endcan
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
