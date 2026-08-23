<?php

declare(strict_types=1);

namespace Agovena\Modules\DigitalDelivery;

use App\Agovena\Modules\Contracts\Module;
use Illuminate\Support\ServiceProvider;

final class DigitalDeliveryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DigitalDeliveryModule::class);
        $this->app->singleton(DigitalSecretFulfillmentService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'digital-delivery');
    }

    public function module(): Module
    {
        return $this->app->make(DigitalDeliveryModule::class);
    }
}
