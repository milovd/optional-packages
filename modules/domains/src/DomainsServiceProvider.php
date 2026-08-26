<?php

declare(strict_types=1);

namespace Agovena\Modules\Domains;

use App\Agovena\Extensions\RuntimeRegistry;
use App\Agovena\Modules\Contracts\Module;
use Illuminate\Support\ServiceProvider;

final class DomainsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DomainRegistrarRegistry::class);
        $this->app->singleton(DomainDnsProviderRegistry::class);
        $this->app->singleton(DomainsModule::class);
        $this->app->singleton(DomainService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'domains');
        app(RuntimeRegistry::class)->register(app(DomainRegistrarRegistry::class));
        app(RuntimeRegistry::class)->register(app(DomainDnsProviderRegistry::class));
    }

    public function module(): Module
    {
        return $this->app->make(DomainsModule::class);
    }
}
