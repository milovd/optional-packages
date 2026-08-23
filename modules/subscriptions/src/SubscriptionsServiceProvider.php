<?php

declare(strict_types=1);

namespace Agovena\Modules\Subscriptions;

use App\Agovena\Modules\Contracts\Module;
use App\Agovena\Subscriptions\ProcessesSubscriptionRenewals;
use Illuminate\Support\ServiceProvider;

final class SubscriptionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SubscriptionsModule::class);
        $this->app->singleton(SubscriptionService::class);
        $this->app->bind(ProcessesSubscriptionRenewals::class, SubscriptionService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'subscriptions');
    }

    public function module(): Module
    {
        return $this->app->make(SubscriptionsModule::class);
    }
}
