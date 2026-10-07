<section class="ag-section" aria-labelledby="product-event-title">
    <header class="ag-section__header">
        <h3 id="product-event-title" class="ag-section__title">{{ __('events::admin.product_event_title') }}</h3>
        <p class="ag-section__lede">{{ __('events::admin.product_event_lede') }}</p>
    </header>
    <form class="ag-section__body ag-form" wire:submit="save">
        <div class="ag-grid ag-grid--2">
            <div class="ag-field">
                <label class="ag-field__label" for="product-event-name">{{ __('events::admin.name') }}</label>
                <input id="product-event-name" class="ag-input" wire:model="eventName" required>
                @error('eventName') <p class="ag-field__error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="ag-field">
                <label class="ag-field__label" for="product-ticket-name">{{ __('events::admin.ticket_name') }}</label>
                <input id="product-ticket-name" class="ag-input" wire:model="ticketName" required>
                @error('ticketName') <p class="ag-field__error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="ag-field">
                <label class="ag-field__label" for="product-event-venue">{{ __('events::admin.venue') }}</label>
                <input id="product-event-venue" class="ag-input" wire:model="venue">
            </div>
            <div class="ag-field">
                <label class="ag-field__label" for="product-event-status">{{ __('common.status') }}</label>
                <select id="product-event-status" class="ag-select" wire:model="status">
                    <option value="draft">{{ __('events::admin.status.draft') }}</option>
                    <option value="published">{{ __('events::admin.status.published') }}</option>
                    <option value="cancelled">{{ __('events::admin.status.cancelled') }}</option>
                </select>
            </div>
            <div class="ag-field">
                <label class="ag-field__label" for="product-event-start">{{ __('events::admin.starts_at') }}</label>
                <input id="product-event-start" class="ag-input" type="datetime-local" wire:model="startsAt" required>
                @error('startsAt') <p class="ag-field__error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="ag-field">
                <label class="ag-field__label" for="product-event-capacity">{{ __('events::admin.capacity') }}</label>
                <input id="product-event-capacity" class="ag-input" type="number" min="1" wire:model="capacity" required>
                @error('capacity') <p class="ag-field__error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="ag-field ag-grid__span-2">
                <label class="ag-field__label" for="product-event-description">{{ __('events::admin.description') }}</label>
                <textarea id="product-event-description" class="ag-input" rows="5" wire:model="description"></textarea>
            </div>
        </div>
        <div class="ag-form__actions">
            <button type="submit" class="ag-btn ag-btn--primary">{{ __('events::admin.save_product_event') }}</button>
            @if ($eventId)
                <span class="ag-badge ag-badge--success">{{ __('events::admin.linked_to_product') }}</span>
            @endif
        </div>
    </form>
</section>
