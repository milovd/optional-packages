<?php

declare(strict_types=1);

namespace Agovena\Modules\Domains;

use Agovena\Modules\Domains\Console\RefreshDomainRegistrationsCommand;
use Agovena\Modules\Domains\Providers\DemoDnsProvider;
use Agovena\Modules\Domains\Providers\DemoDomainRegistrar;
use App\Agovena\Extensions\RuntimeRegistry;
use App\Agovena\Modules\Contracts\Module;
use Illuminate\Console\Scheduling\Schedule;
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
        $registrars = app(DomainRegistrarRegistry::class);
        $dnsProviders = app(DomainDnsProviderRegistry::class);
        if (! $this->app->environment('production')) {
            // Demo adapters simulate registration; a production store must use a real registrar.
            $registrars->register(new DemoDomainRegistrar);
            $dnsProviders->register(new DemoDnsProvider);
        }
        app(RuntimeRegistry::class)->register($registrars);
        app(RuntimeRegistry::class)->register($dnsProviders);
        $this->commands([RefreshDomainRegistrationsCommand::class]);
        $this->app->booted(function (): void {
            $this->app->make(Schedule::class)
                ->command('agovena:refresh-domain-registrations')
                ->everyFiveMinutes()
                ->withoutOverlapping();
        });
    }

    public function module(): Module
    {
        return $this->app->make(DomainsModule::class);
    }
}
