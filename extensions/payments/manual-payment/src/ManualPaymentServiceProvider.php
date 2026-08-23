<?php

declare(strict_types=1);

namespace Agovena\Extensions\ManualPayment;

use App\Agovena\Extensions\Contracts\Extension;
use Illuminate\Support\ServiceProvider;

final class ManualPaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ManualPaymentExtension::class);
    }

    public function extension(): Extension
    {
        return $this->app->make(ManualPaymentExtension::class);
    }
}
