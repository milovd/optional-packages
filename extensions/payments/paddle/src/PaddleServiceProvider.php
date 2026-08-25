<?php

declare(strict_types=1);

namespace Agovena\Extensions\Paddle;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionSettingsRepository;
use App\Agovena\Payments\ApplyNormalizedPaymentStatus;
use Illuminate\Support\ServiceProvider;

final class PaddleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaddleExtension::class);
        $this->app->bind(PaddlePaymentGateway::class, function ($app): PaddlePaymentGateway {
            return new PaddlePaymentGateway(
                $app->make(ExtensionSettingsRepository::class),
                $app->make(ApplyNormalizedPaymentStatus::class),
                $app->bound(PaddleApi::class) ? $app->make(PaddleApi::class) : null,
            );
        });
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__).DIRECTORY_SEPARATOR.'lang', 'paddle');
    }

    public function extension(): Extension
    {
        return $this->app->make(PaddleExtension::class);
    }
}
