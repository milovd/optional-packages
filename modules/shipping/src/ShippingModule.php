<?php

declare(strict_types=1);

namespace Agovena\Modules\Shipping;

use Agovena\Modules\Shipping\Http\Livewire\Admin\MethodsIndex;
use Agovena\Modules\Shipping\Http\Livewire\Admin\OrderFulfillment;
use Agovena\Modules\Shipping\Http\Livewire\Admin\OrderReturns;
use Agovena\Modules\Shipping\Http\Livewire\Admin\ReturnShow;
use Agovena\Modules\Shipping\Http\Livewire\Admin\ReturnsIndex;
use Agovena\Modules\Shipping\Http\Livewire\Admin\ZonesIndex;
use Agovena\Modules\Shipping\Http\Livewire\Customer\ReturnCreate;
use Agovena\Modules\Shipping\Http\Livewire\Customer\ReturnsIndex as CustomerReturnsIndex;
use Agovena\Modules\Shipping\Listeners\CancelShipmentsWhenOrderCancelled;
use Agovena\Modules\Shipping\Listeners\CreateShipmentWhenOrderCreated;
use App\Agovena\Admin\NavigationItem;
use App\Agovena\Admin\OrderDetailSection;
use App\Agovena\Catalog\Capabilities\ProductCapabilityDefinition;
use App\Agovena\Customer\AccountNavItem;
use App\Agovena\Modules\Contracts\Module;
use App\Agovena\Modules\ModuleContext;
use App\Events\OrderCancelled;
use App\Events\OrderCreated;
use Illuminate\Support\Facades\Route;

final class ShippingModule implements Module
{
    public function id(): string
    {
        return 'shipping';
    }

    public function register(ModuleContext $context): void
    {
        // Also register physical so Shipping works without Inventory enabled.
        $context->capabilities()->register(new ProductCapabilityDefinition(
            key: 'physical',
            label: 'admin.products.capabilities.physical',
            description: 'admin.products.capabilities.physical_help',
            providedByModule: $this->id(),
        ));

        $context->capabilities()->register(new ProductCapabilityDefinition(
            key: 'shippable',
            label: 'admin.products.capabilities.shippable',
            description: 'admin.products.capabilities.shippable_help',
            requires: ['physical'],
            providedByModule: $this->id(),
        ));

        $context->admin()->permission('shipping.view', 'admin.permissions.shipping.view');
        $context->admin()->permission('shipping.manage', 'admin.permissions.shipping.manage');
        $context->admin()->permission('returns.view', 'admin.permissions.returns.view');
        $context->admin()->permission('returns.manage', 'admin.permissions.returns.manage');

        $context->admin()->navigation(new NavigationItem(
            id: 'shipping-methods',
            label: 'admin.nav.shipping',
            group: 'admin.nav_groups.fulfillment',
            href: '/admin/shipping/methods',
            icon: 'truck',
            sort: 19,
            permission: 'shipping.view',
        ));

        $context->admin()->navigation(new NavigationItem(
            id: 'shipping-returns',
            label: 'admin.nav.returns',
            group: 'admin.nav_groups.fulfillment',
            href: '/admin/shipping/returns',
            icon: 'rotate-ccw',
            sort: 20,
            permission: 'returns.view',
        ));

        $context->admin()->orderDetailSection(new OrderDetailSection(
            id: 'shipping-fulfillment',
            component: OrderFulfillment::class,
            sort: 40,
        ));

        $context->admin()->orderDetailSection(new OrderDetailSection(
            id: 'shipping-returns',
            component: OrderReturns::class,
            sort: 45,
        ));

        $context->customerAccountNav(new AccountNavItem(
            id: 'shipping-returns',
            label: 'shipping::returns.customer_nav',
            route: 'customer.returns',
            section: 'returns',
            sort: 36,
            icon: 'rotate-ccw',
            group: AccountNavItem::GROUP_PURCHASES,
        ));

        $context->listen(OrderCreated::class, CreateShipmentWhenOrderCreated::class);
        $context->listen(OrderCancelled::class, CancelShipmentsWhenOrderCancelled::class);

        $context->adminRoutes(function (): void {
            Route::get('/shipping/methods', MethodsIndex::class)->name('shipping.methods');
            Route::get('/shipping/zones', ZonesIndex::class)->name('shipping.zones');
            Route::get('/shipping/returns', ReturnsIndex::class)->name('shipping.returns');
            Route::get('/shipping/returns/{returnRequest}', ReturnShow::class)->name('shipping.returns.show');
        });

        $context->customerRoutes(function (): void {
            Route::get('/returns', CustomerReturnsIndex::class)->name('returns');
            Route::get('/returns/create/{order}', ReturnCreate::class)->name('returns.create');
        });
    }
}
