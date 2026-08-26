<?php

declare(strict_types=1);

namespace Agovena\Extensions\Convoy;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionSettingsRepository;
use Illuminate\Support\ServiceProvider;

final class ConvoyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ConvoyExtension::class);
        $this->app->singleton(ConvoyProvisioner::class, function ($app): ConvoyProvisioner {
            $api = $app->bound(ConvoyApi::class) ? $app->make(ConvoyApi::class) : $app->make(HttpConvoyApi::class);

            return new ConvoyProvisioner($app->make(ExtensionSettingsRepository::class), $api);
        });
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__).DIRECTORY_SEPARATOR.'lang', 'convoy');
    }

    public function extension(): Extension
    {
        return $this->app->make(ConvoyExtension::class);
    }
}
