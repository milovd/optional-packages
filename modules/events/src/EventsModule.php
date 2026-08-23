<?php

declare(strict_types=1);

namespace Agovena\Modules\Events;

use Agovena\Modules\Events\Http\Controllers\Api\EventTicketController;
use Agovena\Modules\Events\Http\Livewire\Admin\CheckIn;
use Agovena\Modules\Events\Http\Livewire\Admin\CustomerEventTickets;
use Agovena\Modules\Events\Http\Livewire\Admin\EventShow;
use Agovena\Modules\Events\Http\Livewire\Admin\EventsIndex;
use Agovena\Modules\Events\Http\Livewire\Admin\ProductEventTab;
use Agovena\Modules\Events\Http\Livewire\Customer\TicketShow;
use Agovena\Modules\Events\Http\Livewire\Customer\TicketsIndex;
use Agovena\Modules\Events\Listeners\AssertEventCapacityBeforeOrderPlacing;
use Agovena\Modules\Events\Listeners\IssueTicketsWhenOrderPaid;
use Agovena\Modules\Events\Models\EventTicket;
use App\Agovena\Admin\CustomerDetailSection;
use App\Agovena\Admin\NavigationItem;
use App\Agovena\Admin\ProductTab;
use App\Agovena\Catalog\Capabilities\ProductCapabilityDefinition;
use App\Agovena\Customer\AccountNavItem;
use App\Agovena\Customer\AccountOverviewCard;
use App\Agovena\Modules\Contracts\Module;
use App\Agovena\Modules\ModuleContext;
use App\Events\OrderPaid;
use App\Events\OrderPlacing;
use App\Models\Customer;
use Illuminate\Support\Facades\Route;

final class EventsModule implements Module
{
    public function id(): string
    {
        return 'events';
    }

    public function register(ModuleContext $context): void
    {
        $context->capabilities()->register(new ProductCapabilityDefinition(
            key: 'event_ticket',
            label: 'admin.products.capabilities.event_ticket',
            description: 'admin.products.capabilities.event_ticket_help',
            providedByModule: $this->id(),
        ));

        $context->admin()->permission('events.view', 'admin.permissions.events.view');
        $context->admin()->permission('events.manage', 'admin.permissions.events.manage');
        $context->admin()->permission('events.checkin', 'admin.permissions.events.checkin');

        $context->admin()->productTab(new ProductTab(
            id: 'events',
            label: 'admin.products.tabs.events',
            component: ProductEventTab::class,
            sort: 60,
            permission: 'events.view',
        ));

        $context->admin()->navigation(new NavigationItem(
            id: 'events-checkin',
            label: 'admin.nav.events_checkin',
            group: 'admin.nav_groups.operations',
            href: '/admin/events/check-in',
            icon: 'ticket',
            sort: 29,
            permission: 'events.checkin',
        ));

        $context->customerAccountNav(new AccountNavItem(
            id: 'event-tickets',
            label: 'events::customer.nav',
            route: 'customer.event-tickets',
            section: 'event-tickets',
            sort: 28,
            icon: 'ticket',
            group: AccountNavItem::GROUP_SERVICES,
            visible: static function (): bool {
                $customer = current_customer();
                if ($customer === null) {
                    return false;
                }

                return EventTicket::query()
                    ->where('customer_id', $customer->id)
                    ->where('status', '!=', 'void')
                    ->exists();
            },
        ));

        $context->customerAccountOverview(
            'event-tickets',
            static function (Customer $customer): ?AccountOverviewCard {
                $count = EventTicket::query()
                    ->where('customer_id', $customer->id)
                    ->where('status', '!=', 'void')
                    ->count();
                if ($count < 1) {
                    return null;
                }

                return new AccountOverviewCard(
                    id: 'event-tickets',
                    label: 'events::customer.overview_label',
                    countOrValue: (string) $count,
                    routeName: 'customer.event-tickets',
                    sort: 28,
                    hint: 'events::customer.overview_hint',
                );
            },
            28,
        );

        $context->listen(OrderPlacing::class, AssertEventCapacityBeforeOrderPlacing::class);
        $context->listen(OrderPaid::class, IssueTicketsWhenOrderPaid::class);

        $context->admin()->customerDetailSection(new CustomerDetailSection(
            id: 'event-tickets',
            component: CustomerEventTickets::class,
            sort: 25,
            permission: 'events.view',
        ));

        $context->adminRoutes(function (): void {
            Route::get('/events', EventsIndex::class)->name('events.index');
            Route::get('/events/check-in', CheckIn::class)->name('events.checkin');
            Route::get('/events/{event}', EventShow::class)->name('events.show');
        });

        $context->customerRoutes(function (): void {
            Route::get('/event-tickets', TicketsIndex::class)->name('event-tickets');
            Route::get('/event-tickets/{ticket}', TicketShow::class)->name('event-tickets.show');
        });

        $context->apiRoutes(function (): void {
            Route::get('/event-tickets', [EventTicketController::class, 'index'])->name('event-tickets.index');
            Route::get('/event-tickets/{token}', [EventTicketController::class, 'show'])->name('event-tickets.show');
        });
    }
}
