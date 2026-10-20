<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Shasha1589\RazorpayBilling\Enums\SubscriptionStatus;
use Shasha1589\RazorpayBilling\Events;
use Shasha1589\RazorpayBilling\Exceptions\SubscriptionUpdateFailure;
use Shasha1589\RazorpayBilling\Models\Subscription;
use Shasha1589\RazorpayBilling\Tests\Support\User;

beforeEach(function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    Event::fake([Events\SubscriptionUpdated::class, Events\SubscriptionCancelled::class, Events\SubscriptionPaused::class, Events\SubscriptionResumed::class]);

    config(['razorpay-billing.plans' => [
        'pro-monthly' => ['name' => 'Pro', 'period' => 'monthly', 'interval' => 1, 'amount' => 1999900],
        'pro-yearly' => ['name' => 'Pro', 'period' => 'yearly', 'interval' => 1, 'amount' => 19999900],
    ]]);
    Cache::forever('razorpay-billing:plan:pro-monthly', 'plan_00000000000001');
    Cache::forever('razorpay-billing:plan:pro-yearly', 'plan_00000000000002');

    $this->user = User::create(['name' => 'Gaurav Kumar']);
    $this->subscription = new Subscription([
        'type' => 'default',
        'razorpay_id' => 'sub_00000000000001',
        'razorpay_plan_id' => 'plan_00000000000001',
        'plan_key' => 'pro-monthly',
        'status' => SubscriptionStatus::Active,
        'payment_method' => 'card',
        'quantity' => 2,
        'current_end' => '2026-11-01 00:00:00',
    ]);
    $this->subscription->billable()->associate($this->user)->save();
});

/**
 * Fakes the subscriptions API, answering with the active fixture merged with $response.
 */
function fakeSubscriptionApi(array $response = []): void
{
    Http::fake(['api.razorpay.com/v1/subscriptions/*' => Http::response(fixture('subscriptions/active', [
        'id' => 'sub_00000000000001',
        'quantity' => 2,
        'current_end' => Carbon::parse('2026-11-01 00:00:00')->getTimestamp(),
        ...$response,
    ]))]);
}

it('swaps plans now', function () {
    fakeSubscriptionApi(['plan_id' => 'plan_00000000000002']);

    $this->subscription->swap('pro-yearly', 'now');

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && $request->url() === 'https://api.razorpay.com/v1/subscriptions/sub_00000000000001'
        && $request->data() === ['plan_id' => 'plan_00000000000002', 'schedule_change_at' => 'now']);
    expect($this->subscription->fresh())
        ->razorpay_plan_id->toBe('plan_00000000000002')
        ->plan_key->toBe('pro-yearly');
    Event::assertDispatched(Events\SubscriptionUpdated::class);
});

it('schedules a swap at cycle end by default, leaving the current plan in place', function () {
    fakeSubscriptionApi(['has_scheduled_changes' => true]);

    $this->subscription->swap('plan_00000000000002');

    Http::assertSent(fn (Request $request) => $request['schedule_change_at'] === 'cycle_end' && $request['plan_id'] === 'plan_00000000000002');
    expect($this->subscription->fresh()->plan_key)->toBe('pro-monthly');
    Event::assertNotDispatched(Events\SubscriptionUpdated::class);
});

it('updates, increments and decrements quantity', function () {
    fakeSubscriptionApi(['quantity' => 5]);

    $this->subscription->updateQuantity(5, 'now');
    expect($this->subscription->quantity)->toBe(5);

    $this->subscription->incrementQuantity(2);
    $this->subscription->decrementQuantity(10);

    $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0]->data())->all();
    expect($sent)->toBe([
        ['quantity' => 5, 'schedule_change_at' => 'now'],
        ['quantity' => 7, 'schedule_change_at' => 'cycle_end'],
        ['quantity' => 1, 'schedule_change_at' => 'cycle_end'],
    ]);
});

it('refuses updates Razorpay does not allow', function (array $attributes, string $message) {
    Http::fake();
    $this->subscription->update($attributes);

    expect(fn () => $this->subscription->swap('pro-yearly'))->toThrow(SubscriptionUpdateFailure::class, $message);
    Http::assertNothingSent();
})->with([
    'UPI' => [['payment_method' => 'upi'], "can't update upi subscriptions"],
    'eMandate' => [['payment_method' => 'emandate'], "can't update emandate subscriptions"],
    'created' => [['status' => SubscriptionStatus::Created], 'only updates authenticated or active'],
    'paused' => [['status' => SubscriptionStatus::Paused], 'only updates authenticated or active'],
]);

it('rejects an unknown schedule', function () {
    $this->subscription->updateQuantity(3, 'tomorrow');
})->throws(SubscriptionUpdateFailure::class, 'not `tomorrow`');

it('cancels at cycle end with a grace period', function () {
    fakeSubscriptionApi(['has_scheduled_changes' => true]);

    $this->subscription->cancel();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.razorpay.com/v1/subscriptions/sub_00000000000001/cancel'
        && $request->data() === ['cancel_at_cycle_end' => true]);

    $subscription = $this->subscription->fresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->ends_at->toDateTimeString())->toBe('2026-11-01 00:00:00')
        ->and($subscription->cancelled())->toBeTrue()
        ->and($subscription->onGracePeriod())->toBeTrue()
        ->and($subscription->valid())->toBeTrue();
    Event::assertNotDispatched(Events\SubscriptionCancelled::class);
});

it('cancels a subscription that has not started billing immediately', function () {
    $this->subscription->update(['status' => SubscriptionStatus::Authenticated, 'current_end' => null]);
    fakeSubscriptionApi(['status' => 'cancelled', 'ended_at' => 1793000000]);

    $this->subscription->cancel();

    Http::assertSent(fn (Request $request) => $request['cancel_at_cycle_end'] === false);
    expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Cancelled);
});

it('cancels now', function () {
    fakeSubscriptionApi(['status' => 'cancelled', 'ended_at' => 1793000000]);

    $this->subscription->cancelNow();

    $subscription = $this->subscription->fresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($subscription->ends_at->getTimestamp())->toBe(1793000000)
        ->and($subscription->ended())->toBeTrue();
    Event::assertDispatchedTimes(Events\SubscriptionCancelled::class, 1);
});

it('does not touch an ended subscription', function () {
    Http::fake();
    $this->subscription->update(['status' => SubscriptionStatus::Cancelled]);

    expect(fn () => $this->subscription->cancelNow())->toThrow(SubscriptionUpdateFailure::class, 'has ended')
        ->and(fn () => $this->subscription->addOn(100, 'x'))->toThrow(SubscriptionUpdateFailure::class, 'has ended');
    Http::assertNothingSent();
});

it('pauses and resumes', function () {
    Http::fake(['*' => Http::sequence()
        ->push(fixture('subscriptions/active', ['id' => 'sub_00000000000001', 'quantity' => 2, 'status' => 'paused']))
        ->push(fixture('subscriptions/active', ['id' => 'sub_00000000000001', 'quantity' => 2]))]);

    $this->subscription->pause();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/pause') && $request->data() === ['pause_at' => 'now']);
    expect($this->subscription->fresh()->paused())->toBeTrue();
    Event::assertDispatched(Events\SubscriptionPaused::class);

    $this->subscription->resume();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/resume') && $request->data() === ['resume_at' => 'now']);
    expect($this->subscription->fresh()->active())->toBeTrue();
    Event::assertDispatched(Events\SubscriptionResumed::class);
});

it('only pauses active and resumes paused subscriptions', function () {
    Http::fake();
    $this->subscription->update(['status' => SubscriptionStatus::Authenticated]);

    expect(fn () => $this->subscription->pause())->toThrow(SubscriptionUpdateFailure::class, 'Only active')
        ->and(fn () => $this->subscription->resume())->toThrow(SubscriptionUpdateFailure::class, 'Only paused');
    Http::assertNothingSent();
});

it('adds a one-time add-on to the next invoice', function () {
    Http::fake(['api.razorpay.com/v1/subscriptions/sub_00000000000001/addons' => Http::response([
        'id' => 'ao_00000000000001',
        'entity' => 'addon',
        'quantity' => 1,
        'subscription_id' => 'sub_00000000000001',
        'invoice_id' => null,
    ])]);

    $addOn = $this->subscription->addOn(30000, 'Extra seat');

    expect($addOn['id'])->toBe('ao_00000000000001');
    Http::assertSent(fn (Request $request) => $request->data() === [
        'item' => ['name' => 'Extra seat', 'amount' => 30000, 'currency' => 'INR'],
        'quantity' => 1,
    ]);
});

it('requires a positive add-on amount', function () {
    $this->subscription->addOn(0, 'Nothing');
})->throws(InvalidArgumentException::class);
