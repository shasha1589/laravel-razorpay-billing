<?php

declare(strict_types=1);

use Shasha1589\RazorpayBilling\Support\Signature;

it('verifies a subscription checkout signature over payment_id|subscription_id', function () {
    $signature = hash_hmac('sha256', 'pay_1|sub_1', 'secret');

    expect(Signature::verifySubscriptionPayment('pay_1', 'sub_1', $signature, 'secret'))->toBeTrue();
});

it('verifies an order checkout signature over order_id|payment_id', function () {
    $signature = hash_hmac('sha256', 'order_1|pay_1', 'secret');

    expect(Signature::verifyOrderPayment('order_1', 'pay_1', $signature, 'secret'))->toBeTrue();
});

it('rejects invalid and tampered signatures', function (string $paymentId, string $subscriptionId, string $signature, string $secret) {
    expect(Signature::verifySubscriptionPayment($paymentId, $subscriptionId, $signature, $secret))->toBeFalse();
})->with([
    'wrong secret' => ['pay_1', 'sub_1', hash_hmac('sha256', 'pay_1|sub_1', 'other'), 'secret'],
    'tampered payment id' => ['pay_2', 'sub_1', hash_hmac('sha256', 'pay_1|sub_1', 'secret'), 'secret'],
    'tampered subscription id' => ['pay_1', 'sub_2', hash_hmac('sha256', 'pay_1|sub_1', 'secret'), 'secret'],
    'swapped order' => ['pay_1', 'sub_1', hash_hmac('sha256', 'sub_1|pay_1', 'secret'), 'secret'],
    'empty signature' => ['pay_1', 'sub_1', '', 'secret'],
    'empty secret' => ['pay_1', 'sub_1', hash_hmac('sha256', 'pay_1|sub_1', ''), ''],
]);
