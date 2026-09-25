<?php

declare(strict_types=1);

use Agovena\Extensions\PayPal\PayPalCheckoutPage;
use Illuminate\Support\Facades\Route;

Route::get('/paypal/checkout', PayPalCheckoutPage::class)
    ->middleware('web')
    ->name('paypal.checkout');
