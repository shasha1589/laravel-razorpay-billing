<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Shasha1589\RazorpayBilling\Client\RazorpayClient;
use Shasha1589\RazorpayBilling\Enums\SubscriptionStatus;
use Shasha1589\RazorpayBilling\Models\Subscription;
use Shasha1589\RazorpayBilling\Tests\Support\User;

beforeEach(function () {
    $user = User::create(['name' => 'Gaurav Kumar']);
    $this->subscription = new Subscription([
        'type' => 'default',
        'razorpay_id' => 'sub_00000000000001',
        'razorpay_plan_id' => 'plan_00000000000001',
        'status' => SubscriptionStatus::Created,
    ]);
    $this->subscription->billable()->associate($user)->save();
});

function signedPayload(string $paymentId = 'pay_1', string $subscriptionId = 'sub_00000000000001', string $secret = 'dummy_secret'): array
{
    return [
        'razorpay_payment_id' => $paymentId,
        'razorpay_subscription_id' => $subscriptionId,
        'razorpay_signature' => hash_hmac('sha256', "{$paymentId}|{$subscriptionId}", $secret),
    ];
}

it('verifies a valid signature and syncs the subscription', function () {
    Http::fake(['api.razorpay.com/v1/subscriptions/sub_00000000000001' => Http::response(fixture('subscriptions/active'))]);

    $this->postJson(route('razorpay-billing.verify'), signedPayload())
        ->assertOk()
        ->assertExactJson([
            'verified' => true,
            'subscription' => ['id' => 'sub_00000000000001', 'type' => 'default', 'status' => 'active', 'valid' => true],
        ]);

    expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
});

it('is served under the configured path', function () {
    expect(route('razorpay-billing.verify'))->toBe(url('razorpay/verify'));
});

it('rejects an invalid signature', function () {
    Http::fake();

    $this->postJson(route('razorpay-billing.verify'), signedPayload(secret: 'wrong'))
        ->assertForbidden()
        ->assertJson(['message' => 'Invalid signature.']);

    Http::assertNothingSent();
    expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Created);
});

it('rejects a signature for a different payment', function () {
    Http::fake();
    $payload = [...signedPayload('pay_1'), 'razorpay_payment_id' => 'pay_2'];

    $this->postJson(route('razorpay-billing.verify'), $payload)->assertForbidden();
});

it('returns 404 for an unknown subscription', function () {
    $this->postJson(route('razorpay-billing.verify'), signedPayload(subscriptionId: 'sub_unknown'))->assertNotFound();
});

it('validates the payload', function () {
    $this->postJson(route('razorpay-billing.verify'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['razorpay_payment_id', 'razorpay_subscription_id', 'razorpay_signature']);
});

it('still verifies when the Razorpay sync fails', function () {
    Http::fake(['*' => Http::response([], 500)]);
    config(['razorpay-billing.api.retries' => 0]);
    app()->forgetInstance(RazorpayClient::class);

    $this->postJson(route('razorpay-billing.verify'), signedPayload())
        ->assertOk()
        ->assertJsonPath('subscription.status', 'created');
});
