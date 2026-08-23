<?php

declare(strict_types=1);

namespace Agovena\Modules\Shipping;

use App\Agovena\Checkout\ShippingQuoteResolver;
use App\Agovena\Fulfillment\OrderFulfillmentPresenter;
use App\Agovena\Modules\Contracts\Module;
use Illuminate\Support\ServiceProvider;

final class ShippingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ShippingModule::class);
        $this->app->singleton(ShippingRateCalculator::class);
        $this->app->singleton(ShipmentService::class);
        $this->app->singleton(ReturnRequestService::class);
        $this->app->singleton(ModuleShippingQuoteResolver::class);
        $this->app->singleton(ShippingOrderFulfillmentPresenter::class);

        $this->app->singleton(ShippingQuoteResolver::class, ModuleShippingQuoteResolver::class);
        $this->app->singleton(OrderFulfillmentPresenter::class, ShippingOrderFulfillmentPresenter::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'shipping');
    }

    public function module(): Module
    {
        return $this->app->make(ShippingModule::class);
    }
}
