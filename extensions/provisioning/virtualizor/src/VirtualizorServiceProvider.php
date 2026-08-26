<?php

declare(strict_types=1);

namespace Agovena\Extensions\Virtualizor;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionSettingsRepository;
use Illuminate\Support\ServiceProvider;

final class VirtualizorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(VirtualizorExtension::class);
        $this->app->singleton(VirtualizorProvisioner::class, function ($app): VirtualizorProvisioner {
            $api = $app->bound(VirtualizorApi::class) ? $app->make(VirtualizorApi::class) : $app->make(HttpVirtualizorApi::class);

            return new VirtualizorProvisioner($app->make(ExtensionSettingsRepository::class), $api);
        });
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__).DIRECTORY_SEPARATOR.'lang', 'virtualizor');
    }

    public function extension(): Extension
    {
        return $this->app->make(VirtualizorExtension::class);
    }
}
