<div class="admin-page c-events c-events--detail">
    <x-ag.back :href="route('admin.events.index')" :label="__('events::admin.back')" />
    <x-ag.page-header :heading="$event->name" :lede="$event->venue" />

    @if (session('status'))
        <p class="ag-alert ag-alert--success" role="status">{{ session('status') }}</p>
    @endif

    <section class="ag-card c-events__section" aria-labelledby="event-details-heading">
        <header class="ag-card__header c-events__heading">
            <x-ag.icon-tile name="calendar" tone="blue" :size="20" />
            <h2 id="event-details-heading" class="ag-card__title">{{ __('events::admin.details') }}</h2>
        </header>
        <div class="ag-card__content">
            @can('events.manage')
                <form class="ag-form c-events__form" wire:submit="save">
                    <div class="c-events__fields">
                        <div class="ag-field">
                            <label class="ag-field__label" for="ev-name">{{ __('events::admin.name') }}</label>
                            <input id="ev-name" class="ag-input" wire:model="name">
                            @error('name') <p class="ag-field__error">{{ $message }}</p> @enderror
                        </div>
                        <div class="ag-field">
                            <label class="ag-field__label" for="ev-venue">{{ __('events::admin.venue') }}</label>
                            <input id="ev-venue" class="ag-input" wire:model="venue">
                            @error('venue') <p class="ag-field__error">{{ $message }}</p> @enderror
                        </div>
                        <div class="ag-field c-events__field--wide">
                            <label class="ag-field__label" for="ev-description">{{ __('events::admin.description') }}</label>
                            <textarea id="ev-description" class="ag-input" rows="4" wire:model="description"></textarea>
                            @error('description') <p class="ag-field__error">{{ $message }}</p> @enderror
                        </div>
                        <div class="ag-field">
                            <label class="ag-field__label" for="ev-status">{{ __('common.status') }}</label>
                            <select id="ev-status" class="ag-select" wire:model="status">
                                <option value="draft">{{ __('events::admin.status.draft') }}</option>
                                <option value="published">{{ __('events::admin.status.published') }}</option>
                                <option value="cancelled">{{ __('events::admin.status.cancelled') }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="c-events__actions">
                        <button class="ag-btn ag-btn--primary" type="submit">{{ __('common.save') }}</button>
                    </div>
                </form>
            @else
                <dl class="c-events__facts">
                    <div><dt>{{ __('events::admin.name') }}</dt><dd>{{ $event->name }}</dd></div>
                    <div><dt>{{ __('events::admin.venue') }}</dt><dd>{{ $event->venue ?: '-' }}</dd></div>
                    <div class="c-events__field--wide"><dt>{{ __('events::admin.description') }}</dt><dd>{{ $event->description ?: '-' }}</dd></div>
                    <div><dt>{{ __('common.status') }}</dt><dd><x-ag.badge :variant="match ($event->status->value) { 'published' => 'success', 'draft' => 'warning', default => 'muted' }">{{ __('events::admin.status.'.$event->status->value) }}</x-ag.badge></dd></div>
                </dl>
            @endcan
        </div>
    </section>

    <section class="ag-card c-events__section" aria-labelledby="event-performances-heading">
        <header class="ag-card__header c-events__heading">
            <x-ag.icon-tile name="calendar" tone="violet" :size="20" />
            <h2 id="event-performances-heading" class="ag-card__title">{{ __('events::admin.performances') }}</h2>
        </header>
        <div class="ag-card__content">
            @can('events.manage')
                <form class="ag-form c-events__form" wire:submit="addPerformance">
                    <div class="c-events__fields">
                        <div class="ag-field">
                            <label class="ag-field__label" for="perf-start">{{ __('events::admin.starts_at') }}</label>
                            <x-ag.date-input id="perf-start" class="ag-input" type="datetime-local" wire:model="performance_starts_at" />
                            @error('performance_starts_at') <p class="ag-field__error">{{ $message }}</p> @enderror
                        </div>
                        <div class="ag-field">
                            <label class="ag-field__label" for="perf-cap">{{ __('events::admin.capacity') }}</label>
                            <input id="perf-cap" class="ag-input" type="number" min="1" wire:model.number="performance_capacity">
                            @error('performance_capacity') <p class="ag-field__error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="c-events__actions">
                        <button class="ag-btn ag-btn--secondary" type="submit">{{ __('events::admin.add_performance') }}</button>
                    </div>
                </form>
            @endcan
            @if ($event->performances->isEmpty())
                <x-ag.empty :title="__('events::admin.no_performances')">
                    <x-slot:icon><x-ag.icon name="calendar" :size="24" /></x-slot:icon>
                </x-ag.empty>
            @else
                <div class="ag-table-wrap c-events__table-wrap" role="region" aria-label="{{ __('events::admin.performances') }}" tabindex="0">
                    <table class="ag-table c-events__table">
                        <thead><tr>
                            <th scope="col">{{ __('events::admin.starts_at') }}</th>
                            <th scope="col">{{ __('events::admin.capacity') }}</th>
                            <th scope="col">{{ __('events::admin.remaining') }}</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($event->performances as $performance)
                                <tr wire:key="event-performance-{{ $performance->id }}">
                                    <td class="c-events__identity">{{ $performance->starts_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                                    <td class="c-events__detail" data-label="{{ __('events::admin.capacity') }}">{{ $performance->capacity }}</td>
                                    <td class="c-events__detail" data-label="{{ __('events::admin.remaining') }}">{{ $remaining[$performance->id] ?? 0 }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </section>

    <section class="ag-card c-events__section" aria-labelledby="event-ticket-types-heading">
        <header class="ag-card__header c-events__heading">
            <x-ag.icon-tile name="ticket" tone="cyan" :size="20" />
            <h2 id="event-ticket-types-heading" class="ag-card__title">{{ __('events::admin.ticket_types') }}</h2>
        </header>
        <div class="ag-card__content">
            @can('events.manage')
                <form class="ag-form c-events__form" wire:submit="attachTicketType">
                    <div class="c-events__fields">
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
                    </div>
                    <div class="c-events__actions">
                        <button class="ag-btn ag-btn--secondary" type="submit">{{ __('events::admin.attach_product') }}</button>
                    </div>
                </form>
            @endcan
            @if ($event->ticketTypes->isEmpty())
                <x-ag.empty :title="__('events::admin.no_ticket_types')">
                    <x-slot:icon><x-ag.icon name="ticket" :size="24" /></x-slot:icon>
                </x-ag.empty>
            @else
                <div class="ag-table-wrap c-events__table-wrap" role="region" aria-label="{{ __('events::admin.ticket_types') }}" tabindex="0">
                    <table class="ag-table c-events__table">
                        <thead><tr>
                            <th scope="col">{{ __('events::admin.ticket_type_name') }}</th>
                            <th scope="col">{{ __('events::admin.product') }}</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($event->ticketTypes as $type)
                                <tr wire:key="event-ticket-type-{{ $type->id }}">
                                    <td class="c-events__identity">{{ $type->name }}</td>
                                    <td class="c-events__detail" data-label="{{ __('events::admin.product') }}">{{ $type->product?->name }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </section>
</div>
