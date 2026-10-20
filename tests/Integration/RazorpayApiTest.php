<?php

declare(strict_types=1);

use Shasha1589\RazorpayBilling\Client\Exceptions\NotFoundException;
use Shasha1589\RazorpayBilling\Client\RazorpayClient;

/*
 * Opt-in checks against the real Razorpay API in TEST mode:
 *
 *   RAZORPAY_TEST_KEY_ID=rzp_test_... RAZORPAY_TEST_KEY_SECRET=... composer test:integration
 *
 * Only read-only calls and idempotent customer creation; never run in CI.
 */

beforeEach(function () {
    $keyId = getenv('RAZORPAY_TEST_KEY_ID');
    $secret = getenv('RAZORPAY_TEST_KEY_SECRET');

    if (! is_string($keyId) || ! str_starts_with($keyId, 'rzp_test_') || ! is_string($secret) || $secret === '') {
        $this->markTestSkipped('Set RAZORPAY_TEST_KEY_ID (rzp_test_...) and RAZORPAY_TEST_KEY_SECRET to run integration tests.');
    }

    config(['razorpay-billing.key_id' => $keyId, 'razorpay-billing.key_secret' => $secret]);
    app()->forgetInstance(RazorpayClient::class);
});

it('authenticates and lists plans', function () {
    $page = app(RazorpayClient::class)->plans()->all(['count' => 1]);

    expect($page['entity'])->toBe('collection');
});

it('creates or reuses a customer', function () {
    $customer = app(RazorpayClient::class)->customers()->create([
        'name' => 'Laravel Razorpay Billing',
        'email' => 'integration-test@example.com',
        'fail_existing' => '0',
    ]);

    expect($customer['id'])->toStartWith('cust_');
});

it('maps a missing resource to NotFoundException', function () {
    app(RazorpayClient::class)->subscriptions()->fetch('sub_00000000000000');
})->throws(NotFoundException::class);
