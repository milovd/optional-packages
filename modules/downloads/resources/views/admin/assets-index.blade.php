<div class="admin-page">
    <x-ag.page-header :heading="__('digital::admin.title')" :lede="__('digital::admin.lede')" />

    @if (session('status'))
        <p class="ag-alert ag-alert--success" role="status">{{ session('status') }}</p>
    @endif

    @can('digital.manage')
        <form wire:submit="save" class="ag-form ag-section" style="margin-bottom: 1.5rem;" enctype="multipart/form-data">
            <header class="ag-section__header">
                <h3 class="ag-section__title">{{ __('digital::admin.add') }}</h3>
            </header>
            <div class="ag-section__body ag-grid ag-grid--2">
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
                </div>
                <div class="ag-field">
                    <label class="ag-field__label" for="d-limit">{{ __('digital::admin.download_limit') }}</label>
                    <input id="d-limit" class="ag-input" type="number" min="1" wire:model="download_limit">
                    <p class="ag-field__hint">{{ __('digital::admin.download_limit_hint') }}</p>
                </div>
                <div class="ag-field">
                    <label class="ag-field__label" for="d-file">{{ __('digital::admin.file') }}</label>
                    <input id="d-file" class="ag-input" type="file" wire:model="file" required>
                    @error('file') <p class="ag-field__error">{{ $message }}</p> @enderror
                </div>
            </div>
            <button type="submit" class="ag-btn ag-btn--primary">{{ __('digital::admin.save') }}</button>
        </form>
    @endcan

    @if ($assets->isEmpty())
        <p class="ag-muted">{{ __('digital::admin.empty') }}</p>
    @else
        <div class="ag-table-wrap">
            <table class="ag-table">
                <thead>
                    <tr>
                        <th>{{ __('common.product') }}</th>
                        <th>{{ __('digital::admin.label') }}</th>
                        <th>{{ __('digital::admin.filename') }}</th>
                        <th>{{ __('digital::admin.download_limit') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($assets as $asset)
                        <tr wire:key="asset-{{ $asset->id }}">
                            <td>{{ $asset->product?->name }}</td>
                            <td>{{ $asset->label }}</td>
                            <td>{{ $asset->filename }}</td>
                            <td>{{ $asset->download_limit ?? '∞' }}</td>
                            <td>
                                @can('digital.manage')
                                    <button type="button" class="ag-btn ag-btn--ghost" wire:click="delete({{ $asset->id }})">{{ __('common.delete') }}</button>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
