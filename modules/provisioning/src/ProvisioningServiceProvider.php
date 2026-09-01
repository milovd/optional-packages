<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning;

use App\Agovena\Modules\Contracts\Module;
use App\Agovena\Provisioning\Contracts\PollsProvisionedInstances;
use App\Agovena\Provisioning\Contracts\ResolvesProvisionedServices;
use Agovena\Modules\Provisioning\Console\RecoverPlanChangeCompensationsCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class ProvisioningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProvisioningModule::class);
        $this->app->singleton(ProvisioningService::class);
        $this->app->singleton(ProvisioningInstanceMutex::class);
        $this->app->singleton(ServiceInstanceRuntimeSecretStore::class);
        $this->app->singleton(PlanChangeCompensationJournal::class);
        $this->app->singleton(PlanChangeCompensationRecovery::class);
        $this->app->bind(ResolvesProvisionedServices::class, EloquentProvisionedServiceResolver::class);
        $this->app->bind(PollsProvisionedInstances::class, ProvisioningService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'provisioning');
        $this->commands([RecoverPlanChangeCompensationsCommand::class]);
        $this->app->booted(function (): void {
            $this->app->make(Schedule::class)
                ->command('agovena:recover-plan-change-compensations')
                ->everyMinute();
        });
    }

    public function module(): Module
    {
        return $this->app->make(ProvisioningModule::class);
    }
}
