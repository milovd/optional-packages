<?php

declare(strict_types=1);

namespace Agovena\Extensions\NamecheapDomain;

use App\Agovena\Extensions\Contracts\Extension;
use Illuminate\Support\ServiceProvider;

final class NamecheapDomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NamecheapApi::class, HttpNamecheapApi::class);
        $this->app->singleton(NamecheapRegistrar::class);
        $this->app->singleton(NamecheapDomainExtension::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__).DIRECTORY_SEPARATOR.'lang', 'namecheap-domain');
    }

    public function extension(): Extension
    {
        return $this->app->make(NamecheapDomainExtension::class);
    }
}
