<?php

declare(strict_types=1);

namespace Agovena\Extensions\Plesk;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionSettingsRepository;
use Illuminate\Support\ServiceProvider;

final class PleskServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PleskExtension::class);
        $this->app->singleton(PleskProvisioner::class, function ($app): PleskProvisioner {
            $api = $app->bound(PleskApi::class) ? $app->make(PleskApi::class) : $app->make(HttpPleskApi::class);

            return new PleskProvisioner($app->make(ExtensionSettingsRepository::class), $api);
        });
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__).DIRECTORY_SEPARATOR.'lang', 'plesk');
    }

    public function extension(): Extension
    {
        return $this->app->make(PleskExtension::class);
    }
}
