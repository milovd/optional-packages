<?php

declare(strict_types=1);

namespace Agovena\Extensions\DirectAdmin;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionSettingsRepository;
use Illuminate\Support\ServiceProvider;

final class DirectAdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DirectAdminExtension::class);
        $this->app->singleton(DirectAdminProvisioner::class, function ($app): DirectAdminProvisioner {
            $api = $app->bound(DirectAdminApi::class) ? $app->make(DirectAdminApi::class) : $app->make(HttpDirectAdminApi::class);

            return new DirectAdminProvisioner($app->make(ExtensionSettingsRepository::class), $api);
        });
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__).DIRECTORY_SEPARATOR.'lang', 'directadmin');
    }

    public function extension(): Extension
    {
        return $this->app->make(DirectAdminExtension::class);
    }
}
