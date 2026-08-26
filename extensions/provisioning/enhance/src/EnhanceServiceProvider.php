<?php

declare(strict_types=1);

namespace Agovena\Extensions\Enhance;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionSettingsRepository;
use Illuminate\Support\ServiceProvider;

final class EnhanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EnhanceExtension::class);
        $this->app->singleton(EnhanceProvisioner::class, function ($app): EnhanceProvisioner {
            $api = $app->bound(EnhanceApi::class) ? $app->make(EnhanceApi::class) : $app->make(HttpEnhanceApi::class);

            return new EnhanceProvisioner($app->make(ExtensionSettingsRepository::class), $api);
        });
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__).DIRECTORY_SEPARATOR.'lang', 'enhance');
    }

    public function extension(): Extension
    {
        return $this->app->make(EnhanceExtension::class);
    }
}
