<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Shasha1589\RazorpayBilling\Enums\PaymentStatus;
use Shasha1589\RazorpayBilling\Events\PaymentCaptured;
use Shasha1589\RazorpayBilling\Events\RefundProcessed;
use Shasha1589\RazorpayBilling\Exceptions\RefundFailure;
use Shasha1589\RazorpayBilling\Models\Payment;
use Shasha1589\RazorpayBilling\Tests\Support\User;

beforeEach(function () {
    $this->user = User::create(['name' => 'Gaurav Kumar', 'email' => 'gaurav.kumar@example.com']);
});

it('creates an order with billable notes and returns a Checkout', function () {
    Http::fake(['api.razorpay.com/v1/orders' => Http::response(jsonFixture('orders/order'))]);

    $checkout = $this->user->charge(5000, ['receipt' => 'receipt#1', 'notes' => ['sku' => 'ebook'], 'description' => 'E-book']);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.razorpay.com/v1/orders'
        && $request->data() === [
            'amount' => 5000,
            'currency' => 'INR',
            'notes' => ['sku' => 'ebook', 'billable_type' => User::class, 'billable_id' => (string) $this->user->id],
            'receipt' => 'receipt#1',
        ]);

    expect($checkout->orderId())->toBe('order_RB58MiP5SPFYyM')
        ->and($checkout->options())->toMatchArray([
            'key' => 'rzp_test_dummykey',
            'order_id' => 'order_RB58MiP5SPFYyM',
            'amount' => 5000,
            'currency' => 'INR',
            'description' => 'E-book',
            'prefill' => ['name' => 'Gaurav Kumar', 'email' => 'gaurav.kumar@example.com'],
            'notes' => ['sku' => 'ebook'],
        ])
        ->and($checkout->options())->not->toHaveKey('config');
});

it('validates charge input', function (int $amount, array $options, string $message) {
    Http::fake();

    expect(fn () => $this->user->charge($amount, $options))->toThrow(InvalidArgumentException::class, $message);
    Http::assertNothingSent();
})->with([
    'below minimum' => [99, [], 'at least 100 paise'],
    'long receipt' => [5000, ['receipt' => str_repeat('r', 41)], 'at most 40 characters'],
    'too many notes' => [5000, ['notes' => array_combine(array_map(fn ($i) => "k{$i}", range(1, 14)), array_fill(0, 14, 'v'))], 'at most 13 notes'],
]);

it('stores the payment when the checkout is verified', function () {
    Event::fake([PaymentCaptured::class]);
    Http::fake([
        'api.razorpay.com/v1/payments/pay_DG4ZdRK8ZnXC3k' => Http::response(jsonFixture('payments/payment')),
        'api.razorpay.com/v1/orders/order_RB58MiP5SPFYyM' => Http::response(jsonFixture('orders/order', ['notes' => ['billable_type' => User::class, 'billable_id' => (string) $this->user->id]])),
    ]);

    $this->postJson(route('razorpay-billing.verify'), [
        'razorpay_payment_id' => 'pay_DG4ZdRK8ZnXC3k',
        'razorpay_order_id' => 'order_RB58MiP5SPFYyM',
        'razorpay_signature' => hash_hmac('sha256', 'order_RB58MiP5SPFYyM|pay_DG4ZdRK8ZnXC3k', 'dummy_secret'),
    ])->assertOk()->assertExactJson([
        'verified' => true,
        'payment' => ['id' => 'pay_DG4ZdRK8ZnXC3k', 'order_id' => 'order_RB58MiP5SPFYyM', 'status' => 'captured', 'amount' => 5000],
    ]);

    expect($this->user->payments()->sole()->status)->toBe(PaymentStatus::Captured);
    Event::assertDispatchedTimes(PaymentCaptured::class, 1);
});

it('rejects a tampered order signature', function () {
    Http::fake();

    $this->postJson(route('razorpay-billing.verify'), [
        'razorpay_payment_id' => 'pay_other',
        'razorpay_order_id' => 'order_RB58MiP5SPFYyM',
        'razorpay_signature' => hash_hmac('sha256', 'order_RB58MiP5SPFYyM|pay_DG4ZdRK8ZnXC3k', 'dummy_secret'),
    ])->assertForbidden();

    Http::assertNothingSent();
});

it('rejects a subscription-style signature for an order', function () {
    Http::fake();

    $this->postJson(route('razorpay-billing.verify'), [
        'razorpay_payment_id' => 'pay_DG4ZdRK8ZnXC3k',
        'razorpay_order_id' => 'order_RB58MiP5SPFYyM',
        'razorpay_signature' => hash_hmac('sha256', 'pay_DG4ZdRK8ZnXC3k|order_RB58MiP5SPFYyM', 'dummy_secret'),
    ])->assertForbidden();
});

it('requires an order or subscription id', function () {
    $this->postJson(route('razorpay-billing.verify'), ['razorpay_payment_id' => 'pay_1', 'razorpay_signature' => 'x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['razorpay_order_id', 'razorpay_subscription_id']);
});

function capturedPayment(User $user, array $attributes = []): Payment
{
    $payment = new Payment([
        'razorpay_payment_id' => 'pay_DG4ZdRK8ZnXC3k',
        'razorpay_order_id' => 'order_RB58MiP5SPFYyM',
        'amount' => 5000,
        'currency' => 'INR',
        'status' => PaymentStatus::Captured,
        ...$attributes,
    ]);
    $payment->billable()->associate($user)->save();

    return $payment;
}

it('refunds part of a payment and applies a processed refund at once', function () {
    Event::fake([RefundProcessed::class]);
    $payment = capturedPayment($this->user);
    Http::fake([
        'api.razorpay.com/v1/payments/pay_DG4ZdRK8ZnXC3k/refund' => Http::response(jsonFixture('refunds/refund', ['amount' => 2000, 'payment_id' => 'pay_DG4ZdRK8ZnXC3k'])),
        'api.razorpay.com/v1/payments/pay_DG4ZdRK8ZnXC3k' => Http::response(jsonFixture('payments/payment', ['amount_refunded' => 2000])),
    ]);

    $refund = $payment->refund(2000, ['reason' => 'damaged']);

    expect($refund['id'])->toBe('rfnd_FP8QHiV938haTz')
        ->and($payment->amount_refunded)->toBe(2000)
        ->and($payment->partiallyRefunded())->toBeTrue();
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/refund') && $request->data() === ['amount' => 2000, 'notes' => ['reason' => 'damaged']]);
    Event::assertDispatchedTimes(RefundProcessed::class, 1);

    // The refund.processed webhook that follows does not fire again.
    $this->postWebhook(jsonFixture('webhooks/refund.processed', ['payload' => ['payment' => ['entity' => ['id' => 'pay_DG4ZdRK8ZnXC3k', 'amount' => 5000, 'amount_refunded' => 2000]]]]))->assertOk();
    Event::assertDispatchedTimes(RefundProcessed::class, 1);
});

it('refunds in full without an amount and leaves a pending refund to the webhook', function () {
    Event::fake([RefundProcessed::class]);
    $payment = capturedPayment($this->user);
    Http::fake(['api.razorpay.com/v1/payments/pay_DG4ZdRK8ZnXC3k/refund' => Http::response(jsonFixture('refunds/refund', ['status' => 'pending']))]);

    $payment->refund();

    Http::assertSent(fn (Request $request) => $request->body() === '');
    Http::assertSentCount(1);
    expect($payment->fresh()->amount_refunded)->toBe(0);
    Event::assertNotDispatched(RefundProcessed::class);
});

it('marks a fully refunded payment', function () {
    $payment = capturedPayment($this->user);
    Http::fake([
        'api.razorpay.com/v1/payments/pay_DG4ZdRK8ZnXC3k/refund' => Http::response(jsonFixture('refunds/refund')),
        'api.razorpay.com/v1/payments/pay_DG4ZdRK8ZnXC3k' => Http::response(jsonFixture('payments/payment', ['amount_refunded' => 5000, 'status' => 'refunded'])),
    ]);

    $payment->refund();

    expect($payment->refunded())->toBeTrue()
        ->and($payment->partiallyRefunded())->toBeFalse()
        ->and($payment->status)->toBe(PaymentStatus::Refunded);
});

it('validates refunds before calling Razorpay', function (array $attributes, ?int $amount, string $message) {
    Http::fake();
    $payment = capturedPayment($this->user, $attributes);

    expect(fn () => $payment->refund($amount))->toThrow(RefundFailure::class, $message);
    Http::assertNothingSent();
})->with([
    'not captured' => [['status' => PaymentStatus::Failed], null, 'Only captured payments'],
    'too much' => [['amount_refunded' => 4000], 1001, '1000 paise is refundable'],
    'zero' => [[], 0, 'Cannot refund 0 paise'],
]);

it('lists payments on the billable', function () {
    capturedPayment($this->user);

    expect($this->user->payments)->toHaveCount(1);
});
