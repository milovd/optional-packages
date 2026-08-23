<?php

declare(strict_types=1);

namespace Agovena\Extensions\Mollie;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionSettingsRepository;
use App\Agovena\Payments\ApplyNormalizedPaymentStatus;
use Illuminate\Support\ServiceProvider;

final class MollieServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MollieExtension::class);
        $this->app->bind(MolliePaymentGateway::class, function ($app): MolliePaymentGateway {
            return new MolliePaymentGateway(
                $app->make(ExtensionSettingsRepository::class),
                $app->make(ApplyNormalizedPaymentStatus::class),
                $app->bound(MollieApi::class) ? $app->make(MollieApi::class) : null,
            );
        });
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__).DIRECTORY_SEPARATOR.'lang', 'mollie');
        $this->loadMigrationsFrom(dirname(__DIR__).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations');
    }

    public function extension(): Extension
    {
        return $this->app->make(MollieExtension::class);
    }
}
