<div class="admin-page">
    <x-ag.page-header :heading="__('events::admin.checkin_title')" :lede="__('events::admin.checkin_lede')" />

    <section class="admin-panel">
        <form class="ag-form" wire:submit="submit">
            <div class="ag-field">
                <label class="ag-field__label" for="ticket-code">{{ __('events::admin.code') }}</label>
                <input id="ticket-code" class="ag-input" autocomplete="off" wire:model="code">
                @error('code') <p class="ag-field__error">{{ $message }}</p> @enderror
            </div>
            <button class="ag-btn ag-btn--primary" type="submit">{{ __('events::admin.checkin') }}</button>
        </form>
    </section>

    @if ($ticket)
        <p class="ag-alert {{ $already ? 'ag-alert--warning' : 'ag-alert--success' }}" role="status">
            {{ $already ? __('events::admin.already_checked_in') : __('events::admin.checkin_ok') }}
        </p>
        <section class="admin-panel">
            <dl class="ag-dl">
                <div><dt>{{ __('events::admin.ticket_number') }}</dt><dd>{{ $ticket->number }}</dd></div>
                <div><dt>{{ __('events::admin.name') }}</dt><dd>{{ $ticket->event?->name }}</dd></div>
                <div><dt>{{ __('events::admin.starts_at') }}</dt><dd>{{ $ticket->performance?->starts_at?->format('Y-m-d H:i') }}</dd></div>
                <div><dt>{{ __('events::admin.attendee') }}</dt><dd>{{ $ticket->customer_name }}</dd></div>
                <div><dt>{{ __('events::admin.checked_in_at') }}</dt><dd>{{ $ticket->checked_in_at?->format('Y-m-d H:i') }}</dd></div>
            </dl>
        </section>
    @endif
</div>
