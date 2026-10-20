<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Shasha1589\RazorpayBilling\Enums\PaymentStatus;
use Shasha1589\RazorpayBilling\Enums\SubscriptionStatus;
use Shasha1589\RazorpayBilling\Events\InvoiceExpired;
use Shasha1589\RazorpayBilling\Events\InvoicePaid;
use Shasha1589\RazorpayBilling\Events\MandateCancelled;
use Shasha1589\RazorpayBilling\Events\MandatePaused;
use Shasha1589\RazorpayBilling\Events\PaymentCaptured;
use Shasha1589\RazorpayBilling\Events\PaymentFailed;
use Shasha1589\RazorpayBilling\Events\RefundProcessed;
use Shasha1589\RazorpayBilling\Models\Payment;
use Shasha1589\RazorpayBilling\Models\Subscription;
use Shasha1589\RazorpayBilling\Tests\Support\User;

beforeEach(function () {
    Event::fake([PaymentCaptured::class, PaymentFailed::class, RefundProcessed::class, InvoicePaid::class, InvoiceExpired::class, MandateCancelled::class, MandatePaused::class]);
    $this->user = User::create(['name' => 'Gaurav Kumar']);
    $this->billableNotes = ['billable_type' => User::class, 'billable_id' => (string) $this->user->id];
});

/**
 * Fakes GET /orders/{id}, answering with the given notes for every order.
 */
function fakeOrderNotes(array $notes): void
{
    Http::fake(['api.razorpay.com/v1/orders/*' => fn ($request) => Http::response(fixture('orders/order', ['id' => basename($request->url()), 'notes' => $notes]))]);
}

it('stores a captured payment linked through its order notes', function () {
    fakeOrderNotes($this->billableNotes);

    $this->postWebhook(fixture('webhooks/payment.captured'))->assertOk();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.razorpay.com/v1/orders/order_DESlLckIVRkHWj');

    $payment = Payment::query()->sole();
    expect($payment->status)->toBe(PaymentStatus::Captured)
        ->and($payment->amount)->toBe(100)
        ->and($payment->razorpay_order_id)->toBe('order_DESlLckIVRkHWj')
        ->and($payment->method)->toBe('netbanking')
        ->and($payment->captured_at)->not->toBeNull()
        ->and($payment->billable->is($this->user))->toBeTrue();
    Event::assertDispatched(PaymentCaptured::class, fn ($e) => $e->payment->is($payment) && $e->billable->is($this->user));
});

it('ignores payments whose order was not created by this package', function () {
    fakeOrderNotes([]);

    $this->postWebhook(fixture('webhooks/payment.captured'))->assertOk();

    expect(Payment::query()->count())->toBe(0);
    Event::assertNotDispatched(PaymentCaptured::class);
});

it('ignores payments whose order is not found', function () {
    Http::fake(['api.razorpay.com/v1/orders/*' => Http::response(['error' => ['description' => 'not found']], 404)]);

    $this->postWebhook(fixture('webhooks/payment.captured'))->assertOk();

    expect(Payment::query()->count())->toBe(0);
});

it('does not trust billable notes on the payment itself', function () {
    fakeOrderNotes([]);

    $this->postWebhook(fixture('webhooks/payment.captured', ['payload' => ['payment' => ['entity' => ['notes' => $this->billableNotes]]]]))->assertOk();

    expect(Payment::query()->count())->toBe(0);
});

it('stores a failed payment', function () {
    fakeOrderNotes($this->billableNotes);

    $this->postWebhook(fixture('webhooks/payment.failed'))->assertOk();

    expect(Payment::query()->sole()->status)->toBe(PaymentStatus::Failed);
    Event::assertDispatched(PaymentFailed::class);
});

it('never moves a captured payment back to failed', function () {
    fakeOrderNotes($this->billableNotes);
    $captured = fixture('webhooks/payment.captured');
    $failed = $captured;
    $failed['event'] = 'payment.failed';
    $failed['payload']['payment']['entity']['status'] = 'failed';

    $this->postWebhook($captured)->assertOk();
    $this->postWebhook($failed)->assertOk();

    expect(Payment::query()->sole()->status)->toBe(PaymentStatus::Captured);
    Event::assertNotDispatched(PaymentFailed::class);
});

it('stores no payment credentials', function () {
    expect(Schema::getColumnListing('razorpay_payments'))
        ->not->toContain('card', 'card_id', 'vpa', 'bank', 'email', 'contact', 'token_id');
});

it('updates the refunded amount on refund.processed and fires once', function () {
    $payment = new Payment(['razorpay_payment_id' => 'pay_FPoJKWQQ8lK13n', 'amount' => 500000, 'currency' => 'INR', 'status' => PaymentStatus::Captured]);
    $payment->billable()->associate($this->user)->save();

    $this->postWebhook(fixture('webhooks/refund.processed'), 'evt_r1')->assertOk();
    $this->postWebhook(fixture('webhooks/refund.processed'), 'evt_r2')->assertOk();

    expect($payment->fresh()->amount_refunded)->toBe(190000)
        ->and($payment->fresh()->partiallyRefunded())->toBeTrue()
        ->and($payment->fresh()->refunded())->toBeFalse();
    Event::assertDispatchedTimes(RefundProcessed::class, 1);
});

it('never lowers the refunded amount', function () {
    $payment = new Payment(['razorpay_payment_id' => 'pay_FPoJKWQQ8lK13n', 'amount' => 500000, 'currency' => 'INR', 'status' => PaymentStatus::Captured, 'amount_refunded' => 300000]);
    $payment->billable()->associate($this->user)->save();

    $this->postWebhook(fixture('webhooks/refund.processed'))->assertOk();

    expect($payment->fresh()->amount_refunded)->toBe(300000);
    Event::assertNotDispatched(RefundProcessed::class);
});

it('fires invoice events, linking the subscription when present', function () {
    $subscription = new Subscription(['type' => 'default', 'razorpay_id' => 'sub_1', 'razorpay_plan_id' => 'plan_1', 'status' => SubscriptionStatus::Active]);
    $subscription->billable()->associate($this->user)->save();

    $this->postWebhook(fixture('webhooks/invoice.paid', ['payload' => ['invoice' => ['entity' => ['subscription_id' => 'sub_1']]]]))->assertOk();
    $this->postWebhook(fixture('webhooks/invoice.expired'))->assertOk();

    Event::assertDispatched(InvoicePaid::class, fn ($e) => $e->subscription?->is($subscription) && $e->billable?->is($this->user) && $e->invoice['id'] === 'inv_DEWHZlfIcdVIXL');
    Event::assertDispatched(InvoiceExpired::class, fn ($e) => $e->subscription === null && $e->billable === null);
});

it('updates the mandate status from token webhooks', function (string $webhook, string $status, string $event) {
    $subscription = new Subscription(['type' => 'default', 'razorpay_id' => 'sub_1', 'razorpay_plan_id' => 'plan_1', 'status' => SubscriptionStatus::Active, 'razorpay_token_id' => 'token_FVIP5DVexjoZjF']);
    $subscription->billable()->associate($this->user)->save();

    $this->postWebhook(fixture("webhooks/{$webhook}"), 'evt_t1')->assertOk();
    $this->postWebhook(fixture("webhooks/{$webhook}"), 'evt_t2')->assertOk();

    expect($subscription->fresh()->mandate_status)->toBe($status);
    Event::assertDispatchedTimes($event, 1);
})->with([
    ['token.cancelled', 'cancelled', MandateCancelled::class],
    ['token.paused', 'paused', MandatePaused::class],
]);

it('ignores tokens it cannot match', function () {
    $this->postWebhook(fixture('webhooks/token.cancelled'))->assertOk();

    Event::assertNotDispatched(MandateCancelled::class);
});
