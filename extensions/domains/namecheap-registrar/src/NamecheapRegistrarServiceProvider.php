<?php

declare(strict_types=1);

namespace Agovena\Extensions\NamecheapRegistrar;

use App\Agovena\Extensions\Contracts\Extension;
use Illuminate\Support\ServiceProvider;

final class NamecheapRegistrarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NamecheapRegistrarExtension::class);
        $this->app->singleton(NamecheapApi::class, HttpNamecheapApi::class);
        $this->app->singleton(NamecheapRegistrar::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__).DIRECTORY_SEPARATOR.'lang', 'namecheap-registrar');
    }

    public function extension(): Extension
    {
        return $this->app->make(NamecheapRegistrarExtension::class);
    }
}
