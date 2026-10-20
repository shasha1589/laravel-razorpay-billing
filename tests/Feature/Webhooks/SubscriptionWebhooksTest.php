<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Shasha1589\RazorpayBilling\Enums\PaymentStatus;
use Shasha1589\RazorpayBilling\Enums\SubscriptionStatus;
use Shasha1589\RazorpayBilling\Events;
use Shasha1589\RazorpayBilling\Models\Payment;
use Shasha1589\RazorpayBilling\Models\Subscription;
use Shasha1589\RazorpayBilling\Tests\Support\User;

beforeEach(function () {
    Event::fake([
        Events\SubscriptionAuthenticated::class, Events\SubscriptionActivated::class, Events\SubscriptionCharged::class,
        Events\SubscriptionPastDue::class, Events\SubscriptionHalted::class, Events\SubscriptionPaused::class,
        Events\SubscriptionResumed::class, Events\SubscriptionCancelled::class, Events\SubscriptionCompleted::class,
        Events\SubscriptionUpdated::class, Events\PaymentCaptured::class,
    ]);

    $this->user = User::create(['name' => 'Gaurav Kumar']);
    $this->subscription = new Subscription([
        'type' => 'default',
        'razorpay_id' => 'sub_00000000000001',
        'razorpay_plan_id' => 'plan_00000000000001',
        'plan_key' => 'pro-monthly',
        'status' => SubscriptionStatus::Created,
    ]);
    $this->subscription->billable()->associate($this->user)->save();
});

it('applies each subscription webhook and fires its event', function (string $webhook, string $status, string $event, SubscriptionStatus $from) {
    $this->subscription->update(['status' => $from]);

    $this->postWebhook(subscriptionWebhook($webhook, $status))->assertOk();

    expect($this->subscription->fresh()->status->value)->toBe($status);
    Event::assertDispatched($event, fn ($e) => $e->subscription->is($this->subscription)
        && $e->billable->is($this->user)
        && $e->payload['event'] === $webhook);
})->with([
    ['subscription.authenticated', 'authenticated', Events\SubscriptionAuthenticated::class, SubscriptionStatus::Created],
    ['subscription.activated', 'active', Events\SubscriptionActivated::class, SubscriptionStatus::Authenticated],
    ['subscription.pending', 'pending', Events\SubscriptionPastDue::class, SubscriptionStatus::Active],
    ['subscription.halted', 'halted', Events\SubscriptionHalted::class, SubscriptionStatus::Pending],
    'recovered from halted' => ['subscription.activated', 'active', Events\SubscriptionActivated::class, SubscriptionStatus::Halted],
    ['subscription.paused', 'paused', Events\SubscriptionPaused::class, SubscriptionStatus::Active],
    ['subscription.resumed', 'active', Events\SubscriptionResumed::class, SubscriptionStatus::Paused],
    ['subscription.cancelled', 'cancelled', Events\SubscriptionCancelled::class, SubscriptionStatus::Active],
    ['subscription.completed', 'completed', Events\SubscriptionCompleted::class, SubscriptionStatus::Active],
]);

it('sets ends_at when cancelled', function () {
    $this->postWebhook(subscriptionWebhook('subscription.cancelled', 'cancelled', ['ended_at' => 1567692729]))->assertOk();

    expect($this->subscription->fresh()->ends_at->getTimestamp())->toBe(1567692729)
        ->and($this->subscription->fresh()->ended())->toBeTrue();
});

it('handles subscription.charged: periods, payment, token and events', function () {
    $this->postWebhook(fixture('webhooks/subscription.charged'))->assertOk();

    $subscription = $this->subscription->fresh();
    $payment = Payment::query()->sole();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->current_end->getTimestamp())->toBe(1572892200)
        ->and($subscription->charge_at->getTimestamp())->toBe(1572892200)
        ->and($subscription->razorpay_token_id)->toBe('token_FVIP5DVexjoZjF')
        ->and($payment->razorpay_payment_id)->toBe('pay_DEXFWroJ6LikKT')
        ->and($payment->razorpay_subscription_id)->toBe('sub_00000000000001')
        ->and($payment->razorpay_invoice_id)->toBe('inv_DEXFWVuM6rPqlK')
        ->and($payment->amount)->toBe(100000)
        ->and($payment->status)->toBe(PaymentStatus::Captured)
        ->and($payment->method)->toBe('card')
        ->and($payment->billable->is($this->user))->toBeTrue();

    Event::assertDispatched(Events\SubscriptionCharged::class, fn ($e) => $e->payment->is($payment));
    Event::assertDispatchedTimes(Events\PaymentCaptured::class, 1);
    Event::assertDispatchedTimes(Events\SubscriptionActivated::class, 1);
});

it('fires SubscriptionCharged once when the same charge is delivered twice', function () {
    $this->postWebhook(fixture('webhooks/subscription.charged'), 'evt_a')->assertOk();
    $this->postWebhook(fixture('webhooks/subscription.charged'), 'evt_b')->assertOk(); // redelivered with a new event ID

    expect(Payment::query()->count())->toBe(1);
    Event::assertDispatchedTimes(Events\SubscriptionCharged::class, 1);
    Event::assertDispatchedTimes(Events\PaymentCaptured::class, 1);
});

it('handles charged before activated', function () {
    $this->postWebhook(fixture('webhooks/subscription.charged'))->assertOk();
    $this->postWebhook(subscriptionWebhook('subscription.activated', 'active', createdAt: 1567690300))->assertOk();

    expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
    Event::assertDispatchedTimes(Events\SubscriptionActivated::class, 1);
});

it('ignores a stale event that arrives after a newer one', function () {
    $this->subscription->update(['status' => SubscriptionStatus::Active]);

    $this->postWebhook(subscriptionWebhook('subscription.activated', 'active', createdAt: 2000))->assertOk();
    $this->postWebhook(subscriptionWebhook('subscription.pending', 'pending', createdAt: 1000))->assertOk();

    expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
    Event::assertNotDispatched(Events\SubscriptionPastDue::class);
});

it('never revives a cancelled subscription', function () {
    $this->postWebhook(subscriptionWebhook('subscription.cancelled', 'cancelled', createdAt: 1000))->assertOk();
    $this->postWebhook(subscriptionWebhook('subscription.activated', 'active', createdAt: 2000))->assertOk();

    expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Cancelled);
    Event::assertNotDispatched(Events\SubscriptionActivated::class);
});

it('creates the local subscription from notes when it is missing', function () {
    $this->subscription->delete();
    $payload = subscriptionWebhook('subscription.activated', 'active', ['id' => 'sub_NEW', 'notes' => ['billable_id' => (string) $this->user->id, 'subscription_type' => 'team']]);

    $this->postWebhook($payload)->assertOk();

    $subscription = Subscription::query()->where('razorpay_id', 'sub_NEW')->sole();
    expect($subscription->type)->toBe('team')
        ->and($subscription->billable->is($this->user))->toBeTrue()
        ->and($subscription->status)->toBe(SubscriptionStatus::Active);
    Event::assertDispatched(Events\SubscriptionActivated::class);
});

it('ignores subscriptions it cannot link to a billable', function () {
    $payload = subscriptionWebhook('subscription.activated', 'active', ['id' => 'sub_OTHER']);
    $payload['payload']['subscription']['entity']['notes'] = [];

    $this->postWebhook($payload)->assertOk();

    expect(Subscription::query()->where('razorpay_id', 'sub_OTHER')->exists())->toBeFalse();
});

it('syncs plan and quantity on subscription.updated', function () {
    config(['razorpay-billing.plans' => ['pro-yearly' => ['name' => 'Pro', 'period' => 'yearly', 'interval' => 1, 'amount' => 19999900]]]);
    Cache::forever('razorpay-billing:plan:pro-yearly', 'plan_YEARLY000000001');
    $this->subscription->update(['status' => SubscriptionStatus::Active]);

    $this->postWebhook(subscriptionWebhook('subscription.updated', 'active', ['plan_id' => 'plan_YEARLY000000001', 'quantity' => 4]))->assertOk();

    expect($this->subscription->fresh())
        ->razorpay_plan_id->toBe('plan_YEARLY000000001')
        ->plan_key->toBe('pro-yearly')
        ->quantity->toBe(4);
    Event::assertDispatched(Events\SubscriptionUpdated::class);
});

it('does not fire events again when a webhook follows a checkout verification', function () {
    Http::fake(['api.razorpay.com/v1/subscriptions/sub_00000000000001' => Http::response(fixture('subscriptions/active', ['id' => 'sub_00000000000001']))]);

    $this->subscription->syncFromRazorpay();
    $this->postWebhook(subscriptionWebhook('subscription.activated', 'active', createdAt: now()->subMinute()->getTimestamp()))->assertOk();

    Event::assertDispatchedTimes(Events\SubscriptionActivated::class, 1);
});
