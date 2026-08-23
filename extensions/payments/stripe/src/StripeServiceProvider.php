<?php

declare(strict_types=1);

namespace Agovena\Extensions\Stripe;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionSettingsRepository;
use App\Agovena\Payments\ApplyNormalizedPaymentStatus;
use Illuminate\Support\ServiceProvider;

final class StripeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StripeExtension::class);
        $this->app->bind(StripePaymentGateway::class, function ($app): StripePaymentGateway {
            return new StripePaymentGateway(
                $app->make(ExtensionSettingsRepository::class),
                $app->make(ApplyNormalizedPaymentStatus::class),
                $app->bound(StripeApi::class) ? $app->make(StripeApi::class) : null,
            );
        });
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__).DIRECTORY_SEPARATOR.'lang', 'stripe');
        $this->loadMigrationsFrom(dirname(__DIR__).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations');
    }

    public function extension(): Extension
    {
        return $this->app->make(StripeExtension::class);
    }
}
