<?php

declare(strict_types=1);

namespace Agovena\Extensions\Pterodactyl;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionSettingsRepository;
use Illuminate\Support\ServiceProvider;

final class PterodactylServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PterodactylExtension::class);
        $this->app->singleton(PterodactylProvisioner::class, function ($app): PterodactylProvisioner {
            return new PterodactylProvisioner(
                $app->make(ExtensionSettingsRepository::class),
                $app->bound(PterodactylApi::class) ? $app->make(PterodactylApi::class) : $app->make(HttpPterodactylApi::class),
            );
        });
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__).DIRECTORY_SEPARATOR.'lang', 'pterodactyl');
        $this->loadMigrationsFrom(dirname(__DIR__).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations');
    }

    public function extension(): Extension
    {
        return $this->app->make(PterodactylExtension::class);
    }
}
