<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Shasha1589\RazorpayBilling\Http\Controllers\VerifyCheckoutController;
use Shasha1589\RazorpayBilling\Http\Controllers\WebhookController;
use Shasha1589\RazorpayBilling\Http\Middleware\VerifyWebhookSignature;

$middleware = config('razorpay-billing.middleware', ['web']);

Route::post('verify', VerifyCheckoutController::class)
    ->middleware(is_array($middleware) ? array_values(array_filter($middleware, 'is_string')) : [])
    ->name('razorpay-billing.verify');

// Outside the `web` group, so no session or CSRF; authenticated by signature.
Route::post('webhook', WebhookController::class)
    ->middleware(['throttle:razorpay-billing-webhooks', VerifyWebhookSignature::class])
    ->name('razorpay-billing.webhook');
