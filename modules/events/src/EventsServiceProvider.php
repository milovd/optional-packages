<?php

declare(strict_types=1);

namespace Agovena\Modules\Events;

use App\Agovena\Modules\Contracts\Module;
use Illuminate\Support\ServiceProvider;

final class EventsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EventsModule::class);
        $this->app->singleton(EventService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'events');
    }

    public function module(): Module
    {
        return $this->app->make(EventsModule::class);
    }
}
