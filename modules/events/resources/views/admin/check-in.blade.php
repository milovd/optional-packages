<div class="admin-page c-events c-events--check-in">
    <x-ag.page-header :heading="__('events::admin.checkin_title')" :lede="__('events::admin.checkin_lede')" />
    @includeIf('events::admin.partials.tabs', ['activeTab' => 'check-in'])

    <section class="ag-card c-events__section c-events__scan" aria-labelledby="event-checkin-heading">
        <header class="ag-card__header c-events__heading">
            <x-ag.icon-tile name="ticket" tone="blue" :size="20" />
            <h2 id="event-checkin-heading" class="ag-card__title">{{ __('events::admin.checkin_title') }}</h2>
        </header>
        <div class="ag-card__content">
            <form class="ag-form c-events__form" wire:submit="submit">
                <div class="ag-field">
                    <label class="ag-field__label" for="ticket-code">{{ __('events::admin.code') }}</label>
                    <input id="ticket-code" class="ag-input" autocomplete="off" wire:model="code">
                    @error('code') <p class="ag-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="c-events__actions">
                    <button class="ag-btn ag-btn--primary" type="submit">{{ __('events::admin.checkin') }}</button>
                </div>
            </form>
        </div>
    </section>

    @if ($ticket)
        <p class="ag-alert {{ $already ? 'ag-alert--warning' : 'ag-alert--success' }}" role="status">
            {{ $already ? __('events::admin.already_checked_in') : __('events::admin.checkin_ok') }}
        </p>
        <section class="ag-card c-events__section c-events__result" aria-labelledby="event-checkin-result-heading">
            <header class="ag-card__header c-events__heading">
                <x-ag.icon-tile name="ticket" :tone="$already ? 'amber' : 'emerald'" :size="20" />
                <h2 id="event-checkin-result-heading" class="ag-card__title">{{ __('events::admin.ticket_number') }}</h2>
            </header>
            <div class="ag-card__content">
                <dl class="ag-dl c-events__facts">
                    <div><dt>{{ __('events::admin.ticket_number') }}</dt><dd>{{ $ticket->number }}</dd></div>
                    <div><dt>{{ __('events::admin.name') }}</dt><dd>{{ $ticket->event?->name }}</dd></div>
                    <div><dt>{{ __('events::admin.starts_at') }}</dt><dd>{{ $ticket->performance?->starts_at?->format('Y-m-d H:i') }}</dd></div>
                    <div><dt>{{ __('events::admin.attendee') }}</dt><dd>{{ $ticket->customer_name }}</dd></div>
                    <div><dt>{{ __('events::admin.checked_in_at') }}</dt><dd>{{ $ticket->checked_in_at?->format('Y-m-d H:i') }}</dd></div>
                </dl>
            </div>
        </section>
    @endif
</div>
