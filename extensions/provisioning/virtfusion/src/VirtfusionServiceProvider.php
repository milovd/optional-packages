<?php

declare(strict_types=1);

namespace Agovena\Extensions\Virtfusion;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionSettingsRepository;
use Illuminate\Support\ServiceProvider;

final class VirtfusionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(VirtfusionExtension::class);
        $this->app->singleton(VirtfusionProvisioner::class, function ($app): VirtfusionProvisioner {
            $api = $app->bound(VirtfusionApi::class) ? $app->make(VirtfusionApi::class) : $app->make(HttpVirtfusionApi::class);

            return new VirtfusionProvisioner($app->make(ExtensionSettingsRepository::class), $api);
        });
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__).DIRECTORY_SEPARATOR.'lang', 'virtfusion');
    }

    public function extension(): Extension
    {
        return $this->app->make(VirtfusionExtension::class);
    }
}
