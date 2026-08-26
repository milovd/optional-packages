<?php

declare(strict_types=1);

namespace Agovena\Extensions\CloudflareRegistrar;

use App\Agovena\Extensions\Contracts\Extension;
use Illuminate\Support\ServiceProvider;

final class CloudflareRegistrarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CloudflareRegistrarExtension::class);
        $this->app->singleton(CloudflareApi::class, HttpCloudflareApi::class);
        $this->app->singleton(CloudflareRegistrar::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__).DIRECTORY_SEPARATOR.'lang', 'cloudflare-registrar');
    }

    public function extension(): Extension
    {
        return $this->app->make(CloudflareRegistrarExtension::class);
    }
}
