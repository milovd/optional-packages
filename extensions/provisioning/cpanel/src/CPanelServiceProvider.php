<?php

declare(strict_types=1);

namespace Agovena\Extensions\CPanel;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionSettingsRepository;
use Illuminate\Support\ServiceProvider;

final class CPanelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CPanelExtension::class);
        $this->app->singleton(CPanelProvisioner::class, function ($app): CPanelProvisioner {
            $api = $app->bound(CPanelApi::class) ? $app->make(CPanelApi::class) : $app->make(HttpCPanelApi::class);

            return new CPanelProvisioner($app->make(ExtensionSettingsRepository::class), $api);
        });
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__).DIRECTORY_SEPARATOR.'lang', 'cpanel');
    }

    public function extension(): Extension
    {
        return $this->app->make(CPanelExtension::class);
    }
}
