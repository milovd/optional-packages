<?php

declare(strict_types=1);

namespace Agovena\Modules\Digital;

use App\Agovena\Modules\Contracts\Module;
use Illuminate\Support\ServiceProvider;

final class DigitalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DigitalModule::class);
        $this->app->singleton(DigitalDeliveryService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'digital');
    }

    public function module(): Module
    {
        return $this->app->make(DigitalModule::class);
    }
}
