<?php

declare(strict_types=1);

namespace Agovena\Extensions\CloudflareDns;

use App\Agovena\Extensions\Contracts\Extension;
use Illuminate\Support\ServiceProvider;

final class CloudflareDnsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CloudflareDnsApi::class, HttpCloudflareDnsApi::class);
        $this->app->singleton(CloudflareDnsProvider::class);
        $this->app->singleton(CloudflareDnsExtension::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__).DIRECTORY_SEPARATOR.'lang', 'cloudflare-dns');
    }

    public function extension(): Extension
    {
        return $this->app->make(CloudflareDnsExtension::class);
    }
}
