<?php

declare(strict_types=1);

namespace Agovena\Extensions\Proxmox;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionSettingsRepository;
use Illuminate\Support\ServiceProvider;

final class ProxmoxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProxmoxExtension::class);
        $this->app->singleton(ProxmoxProvisioner::class, function ($app): ProxmoxProvisioner {
            return new ProxmoxProvisioner(
                $app->make(ExtensionSettingsRepository::class),
                $app->bound(ProxmoxApi::class) ? $app->make(ProxmoxApi::class) : $app->make(HttpProxmoxApi::class),
            );
        });
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__).DIRECTORY_SEPARATOR.'lang', 'proxmox');
        $this->loadMigrationsFrom(dirname(__DIR__).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations');
    }

    public function extension(): Extension
    {
        return $this->app->make(ProxmoxExtension::class);
    }
}
