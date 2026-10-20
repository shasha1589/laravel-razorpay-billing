<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Shasha1589\RazorpayBilling\Client\RazorpayClient;
use Shasha1589\RazorpayBilling\Enums\SubscriptionStatus;
use Shasha1589\RazorpayBilling\Events\SubscriptionActivated;
use Shasha1589\RazorpayBilling\Events\UpcomingCharge;
use Shasha1589\RazorpayBilling\Models\Subscription;
use Shasha1589\RazorpayBilling\Tests\Support\User;

beforeEach(function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $this->user = User::create(['name' => 'Gaurav Kumar']);
});

function commandSubscription(User $user, string $id, array $attributes = []): Subscription
{
    $subscription = new Subscription([
        'type' => 'default',
        'razorpay_id' => $id,
        'razorpay_plan_id' => 'plan_00000000000001',
        'plan_key' => 'pro-monthly',
        'status' => SubscriptionStatus::Active,
        ...$attributes,
    ]);
    $subscription->billable()->associate($user)->save();

    return $subscription;
}

it('syncs every non-terminal subscription from Razorpay', function () {
    Event::fake([SubscriptionActivated::class]);
    commandSubscription($this->user, 'sub_a', ['status' => SubscriptionStatus::Created]);
    commandSubscription($this->user, 'sub_b', ['status' => SubscriptionStatus::Cancelled]);
    Http::fake(['api.razorpay.com/v1/subscriptions/*' => fn (Request $request) => Http::response(fixture('subscriptions/active', ['id' => basename($request->url())]))]);

    $this->artisan('razorpay:sync')->expectsOutputToContain('Synced 1 subscription')->assertSuccessful();

    Http::assertSentCount(1);
    expect(Subscription::query()->where('razorpay_id', 'sub_a')->sole()->status)->toBe(SubscriptionStatus::Active);
    Event::assertDispatchedTimes(SubscriptionActivated::class, 1);
});

it('syncs a single subscription', function () {
    commandSubscription($this->user, 'sub_a');
    commandSubscription($this->user, 'sub_b');
    Http::fake(['*' => Http::response(fixture('subscriptions/active', ['id' => 'sub_b']))]);

    $this->artisan('razorpay:sync', ['--subscription' => 'sub_b'])->assertSuccessful();
    $this->artisan('razorpay:sync', ['--subscription' => 'sub_missing'])->assertFailed();

    Http::assertSentCount(1);
});

it('reports failures and keeps going', function () {
    commandSubscription($this->user, 'sub_a');
    commandSubscription($this->user, 'sub_b');
    config(['razorpay-billing.api.retries' => 0]);
    app()->forgetInstance(RazorpayClient::class);
    Http::fake([
        'api.razorpay.com/v1/subscriptions/sub_a' => Http::response(['error' => ['description' => 'Nope']], 404),
        'api.razorpay.com/v1/subscriptions/sub_b' => Http::response(fixture('subscriptions/active', ['id' => 'sub_b'])),
    ]);

    $this->artisan('razorpay:sync')
        ->expectsOutputToContain('sub_a: Nope')
        ->expectsOutputToContain('Synced 1 subscription(s), 1 failed.')
        ->assertFailed();
});

it('fires UpcomingCharge once per charge within the notice window', function () {
    Event::fake([UpcomingCharge::class]);
    config(['razorpay-billing.plans' => ['pro-monthly' => ['name' => 'Pro', 'period' => 'monthly', 'interval' => 1, 'amount' => 1999900]]]);

    $due = commandSubscription($this->user, 'sub_due', ['charge_at' => '2026-10-21 10:00:00', 'quantity' => 2]);
    commandSubscription($this->user, 'sub_later', ['charge_at' => '2026-10-25 10:00:00']);
    commandSubscription($this->user, 'sub_past', ['charge_at' => '2026-10-19 10:00:00']);
    commandSubscription($this->user, 'sub_ending', ['charge_at' => '2026-10-21 10:00:00', 'ends_at' => '2026-10-21 10:00:00']);
    commandSubscription($this->user, 'sub_paused', ['charge_at' => '2026-10-21 10:00:00', 'status' => SubscriptionStatus::Paused]);
    $trial = commandSubscription($this->user, 'sub_trial', ['charge_at' => '2026-10-22 09:00:00', 'status' => SubscriptionStatus::Authenticated, 'plan_key' => null]);

    $this->artisan('razorpay:upcoming-charges')->assertSuccessful();
    $this->artisan('razorpay:upcoming-charges')->assertSuccessful(); // hourly re-run

    Event::assertDispatchedTimes(UpcomingCharge::class, 2);
    Event::assertDispatched(UpcomingCharge::class, fn (UpcomingCharge $e) => $e->subscription->is($due)
        && $e->billable->is($this->user)
        && $e->amount === 3999800
        && $e->chargeAt->toDateTimeString() === '2026-10-21 10:00:00');
    Event::assertDispatched(UpcomingCharge::class, fn (UpcomingCharge $e) => $e->subscription->is($trial) && $e->amount === null);
});

it('notifies again for the next charge date', function () {
    Event::fake([UpcomingCharge::class]);
    $subscription = commandSubscription($this->user, 'sub_due', ['charge_at' => '2026-10-21 10:00:00']);

    $this->artisan('razorpay:upcoming-charges');
    Carbon::setTestNow('2026-11-20 10:00:00');
    $subscription->update(['charge_at' => '2026-11-21 10:00:00']);
    $this->artisan('razorpay:upcoming-charges');

    Event::assertDispatchedTimes(UpcomingCharge::class, 2);
});
