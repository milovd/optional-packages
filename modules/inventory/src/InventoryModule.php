<?php

declare(strict_types=1);

namespace Agovena\Modules\Inventory;

use Agovena\Modules\Inventory\Http\Livewire\Admin\StocksIndex;
use Agovena\Modules\Inventory\Listeners\AssertStockBeforeOrderPlacing;
use Agovena\Modules\Inventory\Listeners\ReserveStockWhenOrderCreated;
use Agovena\Modules\Inventory\Listeners\RestockWhenOrderCancelled;
use App\Agovena\Admin\NavigationItem;
use App\Agovena\Catalog\Capabilities\ProductCapabilityDefinition;
use App\Agovena\Modules\Contracts\Module;
use App\Agovena\Modules\ModuleContext;
use App\Events\OrderCancelled;
use App\Events\OrderCreated;
use App\Events\OrderPlacing;
use Illuminate\Support\Facades\Route;

final class InventoryModule implements Module
{
    public function id(): string
    {
        return 'inventory';
    }

    public function register(ModuleContext $context): void
    {
        $context->capabilities()->register(new ProductCapabilityDefinition(
            key: 'physical',
            label: 'admin.products.capabilities.physical',
            description: 'admin.products.capabilities.physical_help',
            providedByModule: $this->id(),
        ));

        $context->capabilities()->register(new ProductCapabilityDefinition(
            key: 'inventory',
            label: 'admin.products.capabilities.inventory',
            description: 'admin.products.capabilities.inventory_help',
            requires: ['physical'],
            providedByModule: $this->id(),
        ));

        $context->admin()->permission('inventory.view', 'admin.permissions.inventory.view');
        $context->admin()->permission('inventory.manage', 'admin.permissions.inventory.manage');

        $context->admin()->navigation(new NavigationItem(
            id: 'inventory-stocks',
            label: 'admin.nav.inventory',
            group: 'admin.nav_groups.fulfillment',
            href: '/admin/inventory',
            icon: 'warehouse',
            sort: 18,
            permission: 'inventory.view',
        ));

        $context->listen(OrderPlacing::class, AssertStockBeforeOrderPlacing::class);
        $context->listen(OrderCreated::class, ReserveStockWhenOrderCreated::class);
        $context->listen(OrderCancelled::class, RestockWhenOrderCancelled::class);

        $context->adminRoutes(function (): void {
            Route::get('/inventory', StocksIndex::class)->name('inventory.index');
        });
    }
}
