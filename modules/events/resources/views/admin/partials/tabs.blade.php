<nav class="ag-tabs" aria-label="{{ __('events::admin.tabs_label') }}">
    @can('events.view')
        <a
            @class(['ag-tabs__tab', 'ag-tabs__tab--active' => $activeTab === 'events'])
            href="{{ route('admin.events.index') }}"
            @if ($activeTab === 'events') aria-current="page" @else wire:navigate @endif
        >{{ __('events::admin.title') }}</a>
    @endcan
    @can('events.checkin')
        <a
            @class(['ag-tabs__tab', 'ag-tabs__tab--active' => $activeTab === 'check-in'])
            href="{{ route('admin.events.checkin') }}"
            @if ($activeTab === 'check-in') aria-current="page" @else wire:navigate @endif
        >{{ __('events::admin.checkin_title') }}</a>
    @endcan
</nav>
