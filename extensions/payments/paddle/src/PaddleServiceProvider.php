<?php

declare(strict_types=1);

namespace Agovena\Extensions\Paddle;

use App\Agovena\Extensions\Contracts\Extension;
use App\Agovena\Extensions\ExtensionSettingsRepository;
use App\Agovena\Payments\ApplyProviderRefundEvent;
use App\Agovena\Payments\ApplyNormalizedPaymentStatus;
use Illuminate\Support\Facades\Route;
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
                $app->make(ApplyProviderRefundEvent::class),
                $app->bound(PaddleApi::class) ? $app->make(PaddleApi::class) : null,
            );
        });
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__).DIRECTORY_SEPARATOR.'lang', 'paddle');
        $this->loadViewsFrom(dirname(__DIR__).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views', 'paddle');
        Route::get('/paddle/checkout', PaddleCheckoutPage::class)
            ->middleware('web')
            ->name('paddle.checkout');
    }

    public function extension(): Extension
    {
        return $this->app->make(PaddleExtension::class);
    }
}
