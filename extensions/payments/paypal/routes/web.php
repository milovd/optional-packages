<?php

declare(strict_types=1);

use Agovena\Extensions\PayPal\PayPalCheckoutPage;
use Illuminate\Support\Facades\Route;

// Same suspension and blocked-IP guard as the Core checkout.
Route::get('/paypal/checkout', PayPalCheckoutPage::class)
    ->middleware(['web', 'abuse'])
    ->name('paypal.checkout');
