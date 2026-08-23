<?php

declare(strict_types=1);

namespace Agovena\Extensions\Postnl;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionSettingsRepository;
use Illuminate\Support\ServiceProvider;

final class PostnlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PostnlExtension::class);
        $this->app->singleton(PostnlCarrier::class, function ($app): PostnlCarrier {
            return new PostnlCarrier(
                $app->make(ExtensionSettingsRepository::class),
                $app->bound(PostnlApi::class) ? $app->make(PostnlApi::class) : $app->make(HttpPostnlApi::class),
            );
        });
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__).DIRECTORY_SEPARATOR.'lang', 'postnl');
        $this->loadMigrationsFrom(dirname(__DIR__).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations');
    }

    public function extension(): Extension
    {
        return $this->app->make(PostnlExtension::class);
    }
}
