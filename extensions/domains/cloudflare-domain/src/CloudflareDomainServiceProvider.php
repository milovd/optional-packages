<?php

declare(strict_types=1);

namespace Agovena\Extensions\CloudflareDomain;

use App\Agovena\Extensions\Contracts\Extension;
use Illuminate\Support\ServiceProvider;

final class CloudflareDomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CloudflareDnsApi::class, HttpCloudflareDnsApi::class);
        $this->app->singleton(CloudflareApi::class, HttpCloudflareApi::class);
        $this->app->singleton(CloudflareDnsProvider::class);
        $this->app->singleton(CloudflareRegistrar::class);
        $this->app->singleton(CloudflareDomainExtension::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__).DIRECTORY_SEPARATOR.'lang', 'cloudflare-domain');
    }

    public function extension(): Extension
    {
        return $this->app->make(CloudflareDomainExtension::class);
    }
}
