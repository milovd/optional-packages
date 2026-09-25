<?php

declare(strict_types=1);

namespace Agovena\Extensions\PayPal;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionSettingsRepository;
use App\Agovena\Payments\ApplyNormalizedPaymentStatus;
use Illuminate\Support\ServiceProvider;

final class PayPalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PayPalExtension::class);
        $this->app->bind(PayPalPaymentGateway::class, function ($app): PayPalPaymentGateway {
            return new PayPalPaymentGateway(
                $app->make(ExtensionSettingsRepository::class),
                $app->make(ApplyNormalizedPaymentStatus::class),
                $app->bound(PayPalApi::class) ? $app->make(PayPalApi::class) : null,
            );
        });

        require_once dirname(__DIR__).DIRECTORY_SEPARATOR.'routes'.DIRECTORY_SEPARATOR.'web.php';
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__).DIRECTORY_SEPARATOR.'lang', 'paypal');
        $this->loadViewsFrom(dirname(__DIR__).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views', 'paypal');
        $this->loadMigrationsFrom(dirname(__DIR__).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations');
    }

    public function extension(): Extension
    {
        return $this->app->make(PayPalExtension::class);
    }
}
