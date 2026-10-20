<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Shasha1589\RazorpayBilling\RazorpayBillingServiceProvider;

it('registers the service provider', function () {
    expect(app()->getProviders(RazorpayBillingServiceProvider::class))->not->toBeEmpty();
});

it('merges the package config', function () {
    expect(config('razorpay-billing.currency'))->toBe('INR')
        ->and(config('razorpay-billing.path'))->toBe('razorpay')
        ->and(config('razorpay-billing.upi_autopay_max_amount'))->toBe(1500000)
        ->and(config('razorpay-billing.upcoming_charge_notice_hours'))->toBe(48)
        ->and(config('razorpay-billing.plans'))->toBe([])
        ->and(config('razorpay-billing.invoices.enabled'))->toBeFalse();
});

it('publishes the config file', function () {
    $paths = ServiceProvider::pathsToPublish(RazorpayBillingServiceProvider::class, 'razorpay-billing-config');

    expect($paths)->toHaveCount(1)
        ->and(array_values($paths)[0])->toBe(config_path('razorpay-billing.php'));
});
