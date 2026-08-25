<?php

declare(strict_types=1);

namespace Agovena\Extensions\Tebex;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionSettingsRepository;
use App\Agovena\Payments\ApplyNormalizedPaymentStatus;
use Illuminate\Support\ServiceProvider;

final class TebexServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TebexExtension::class);
        $this->app->bind(TebexPaymentGateway::class, function ($app): TebexPaymentGateway {
            return new TebexPaymentGateway(
                $app->make(ExtensionSettingsRepository::class),
                $app->make(ApplyNormalizedPaymentStatus::class),
                $app->bound(TebexApi::class) ? $app->make(TebexApi::class) : null,
            );
        });
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__).DIRECTORY_SEPARATOR.'lang', 'tebex');
    }

    public function extension(): Extension
    {
        return $this->app->make(TebexExtension::class);
    }
}
