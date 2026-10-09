<div class="admin-page">
    <x-ag.back :href="route('admin.events.index')" :label="__('events::admin.back')" />
    <x-ag.page-header :heading="$event->name" :lede="$event->venue" />

    @if (session('status'))
        <p class="ag-alert ag-alert--success" role="status">{{ session('status') }}</p>
    @endif

    @can('events.manage')
        <section class="admin-panel">
            <h2 class="admin-panel__title">{{ __('events::admin.details') }}</h2>
            <form class="ag-form" wire:submit="save">
                <div class="ag-field">
                    <label class="ag-field__label" for="ev-name">{{ __('events::admin.name') }}</label>
                    <input id="ev-name" class="ag-input" wire:model="name">
                    @error('name') <p class="ag-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="ag-field">
                    <label class="ag-field__label" for="ev-venue">{{ __('events::admin.venue') }}</label>
                    <input id="ev-venue" class="ag-input" wire:model="venue">
                </div>
                <div class="ag-field">
                    <label class="ag-field__label" for="ev-description">{{ __('events::admin.description') }}</label>
                    <textarea id="ev-description" class="ag-input" rows="4" wire:model="description"></textarea>
                </div>
                <div class="ag-field">
                    <label class="ag-field__label" for="ev-status">{{ __('common.status') }}</label>
                    <select id="ev-status" class="ag-select" wire:model="status">
                        <option value="draft">{{ __('events::admin.status.draft') }}</option>
                        <option value="published">{{ __('events::admin.status.published') }}</option>
                        <option value="cancelled">{{ __('events::admin.status.cancelled') }}</option>
                    </select>
                </div>
                <button class="ag-btn ag-btn--primary" type="submit">{{ __('common.save') }}</button>
            </form>
        </section>

        <section class="admin-panel">
            <h2 class="admin-panel__title">{{ __('events::admin.performances') }}</h2>
            <form class="ag-form" wire:submit="addPerformance">
                <div class="ag-field">
                    <label class="ag-field__label" for="perf-start">{{ __('events::admin.starts_at') }}</label>
                    <input id="perf-start" class="ag-input" type="datetime-local" wire:model="performance_starts_at">
                    @error('performance_starts_at') <p class="ag-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="ag-field">
                    <label class="ag-field__label" for="perf-cap">{{ __('events::admin.capacity') }}</label>
                    <input id="perf-cap" class="ag-input" type="number" min="1" wire:model.number="performance_capacity">
                </div>
                <button class="ag-btn ag-btn--secondary" type="submit">{{ __('events::admin.add_performance') }}</button>
            </form>
            <div class="ag-table-wrap">
                <table class="ag-table">
                    <thead>
                        <tr>
                            <th>{{ __('events::admin.starts_at') }}</th>
                            <th>{{ __('events::admin.capacity') }}</th>
                            <th>{{ __('events::admin.remaining') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($event->performances as $performance)
                            <tr>
                                <td>{{ $performance->starts_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                                <td>{{ $performance->capacity }}</td>
                                <td>{{ $remaining[$performance->id] ?? 0 }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3">{{ __('events::admin.no_performances') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="admin-panel">
            <h2 class="admin-panel__title">{{ __('events::admin.ticket_types') }}</h2>
            <form class="ag-form" wire:submit="attachTicketType">
                <div class="ag-field">
                    <label class="ag-field__label" for="tt-product">{{ __('events::admin.product') }}</label>
                    <select id="tt-product" class="ag-select" wire:model.number="ticket_product_id">
                        <option value="">{{ __('events::admin.select_product') }}</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}">{{ $product->name }}</option>
                        @endforeach
                    </select>
                    @error('ticket_product_id') <p class="ag-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="ag-field">
                    <label class="ag-field__label" for="tt-perf">{{ __('events::admin.performance') }}</label>
                    <select id="tt-perf" class="ag-select" wire:model.number="ticket_performance_id">
                        <option value="">{{ __('events::admin.select_performance') }}</option>
                        @foreach ($event->performances as $performance)
                            <option value="{{ $performance->id }}">{{ $performance->starts_at?->format('Y-m-d H:i') }}</option>
                        @endforeach
                    </select>
                    @error('ticket_performance_id') <p class="ag-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="ag-field">
                    <label class="ag-field__label" for="tt-name">{{ __('events::admin.ticket_type_name') }}</label>
                    <input id="tt-name" class="ag-input" wire:model="ticket_name">
                    @error('ticket_name') <p class="ag-field__error">{{ $message }}</p> @enderror
                </div>
                <button class="ag-btn ag-btn--secondary" type="submit">{{ __('events::admin.attach_product') }}</button>
            </form>
            <div class="ag-table-wrap">
                <table class="ag-table">
                    <thead>
                        <tr>
                            <th>{{ __('events::admin.ticket_type_name') }}</th>
                            <th>{{ __('events::admin.product') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($event->ticketTypes as $type)
                            <tr>
                                <td>{{ $type->name }}</td>
                                <td>{{ $type->product?->name }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="2">{{ __('events::admin.no_ticket_types') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endcan
</div>
