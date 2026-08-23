<?php

declare(strict_types=1);

namespace Agovena\Modules\Inventory;

use App\Agovena\Catalog\Contracts\ProductStock;
use App\Agovena\Modules\Contracts\Module;
use Illuminate\Support\ServiceProvider;

final class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InventoryModule::class);
        $this->app->singleton(InventoryService::class);
        $this->app->singleton(ProductStock::class, CatalogProductStock::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'inventory');
    }

    public function module(): Module
    {
        return $this->app->make(InventoryModule::class);
    }
}
