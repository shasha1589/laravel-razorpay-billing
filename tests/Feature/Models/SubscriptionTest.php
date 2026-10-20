<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Shasha1589\RazorpayBilling\Enums\SubscriptionStatus;
use Shasha1589\RazorpayBilling\Models\Subscription;
use Shasha1589\RazorpayBilling\Tests\Support\User;

beforeEach(function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $this->user = User::create(['name' => 'Gaurav Kumar']);
});

function makeSubscription(User $user, array $attributes = []): Subscription
{
    static $n = 0;
    $n++;

    $subscription = new Subscription([
        'type' => 'default',
        'razorpay_id' => "sub_{$n}",
        'razorpay_plan_id' => 'plan_00000000000001',
        'plan_key' => 'pro-monthly',
        'status' => SubscriptionStatus::Active,
        ...$attributes,
    ]);
    $subscription->billable()->associate($user)->save();

    return $subscription;
}

it('reports state helpers', function (array $attributes, array $expected) {
    $subscription = makeSubscription($this->user, $attributes);

    expect([
        'active' => $subscription->active(),
        'onTrial' => $subscription->onTrial(),
        'pastDue' => $subscription->pastDue(),
        'halted' => $subscription->halted(),
        'paused' => $subscription->paused(),
        'cancelled' => $subscription->cancelled(),
        'onGracePeriod' => $subscription->onGracePeriod(),
        'ended' => $subscription->ended(),
        'valid' => $subscription->valid(),
    ])->toBe($expected);
})->with([
    'active' => [['status' => SubscriptionStatus::Active],
        ['active' => true, 'onTrial' => false, 'pastDue' => false, 'halted' => false, 'paused' => false, 'cancelled' => false, 'onGracePeriod' => false, 'ended' => false, 'valid' => true]],
    'created' => [['status' => SubscriptionStatus::Created],
        ['active' => false, 'onTrial' => false, 'pastDue' => false, 'halted' => false, 'paused' => false, 'cancelled' => false, 'onGracePeriod' => false, 'ended' => false, 'valid' => false]],
    'authenticated trial' => [['status' => SubscriptionStatus::Authenticated, 'trial_ends_at' => '2026-10-30 00:00:00'],
        ['active' => false, 'onTrial' => true, 'pastDue' => false, 'halted' => false, 'paused' => false, 'cancelled' => false, 'onGracePeriod' => false, 'ended' => false, 'valid' => true]],
    'expired trial' => [['status' => SubscriptionStatus::Authenticated, 'trial_ends_at' => '2026-10-01 00:00:00'],
        ['active' => false, 'onTrial' => false, 'pastDue' => false, 'halted' => false, 'paused' => false, 'cancelled' => false, 'onGracePeriod' => false, 'ended' => false, 'valid' => false]],
    'pending' => [['status' => SubscriptionStatus::Pending],
        ['active' => false, 'onTrial' => false, 'pastDue' => true, 'halted' => false, 'paused' => false, 'cancelled' => false, 'onGracePeriod' => false, 'ended' => false, 'valid' => false]],
    'halted' => [['status' => SubscriptionStatus::Halted],
        ['active' => false, 'onTrial' => false, 'pastDue' => false, 'halted' => true, 'paused' => false, 'cancelled' => false, 'onGracePeriod' => false, 'ended' => false, 'valid' => false]],
    'paused' => [['status' => SubscriptionStatus::Paused],
        ['active' => false, 'onTrial' => false, 'pastDue' => false, 'halted' => false, 'paused' => true, 'cancelled' => false, 'onGracePeriod' => false, 'ended' => false, 'valid' => false]],
    'cancelling at cycle end' => [['status' => SubscriptionStatus::Active, 'ends_at' => '2026-11-01 00:00:00'],
        ['active' => true, 'onTrial' => false, 'pastDue' => false, 'halted' => false, 'paused' => false, 'cancelled' => true, 'onGracePeriod' => true, 'ended' => false, 'valid' => true]],
    'cancelled' => [['status' => SubscriptionStatus::Cancelled, 'ends_at' => '2026-10-01 00:00:00'],
        ['active' => false, 'onTrial' => false, 'pastDue' => false, 'halted' => false, 'paused' => false, 'cancelled' => true, 'onGracePeriod' => false, 'ended' => true, 'valid' => false]],
    'completed' => [['status' => SubscriptionStatus::Completed],
        ['active' => false, 'onTrial' => false, 'pastDue' => false, 'halted' => false, 'paused' => false, 'cancelled' => false, 'onGracePeriod' => false, 'ended' => true, 'valid' => false]],
]);

it('keeps past-due subscriptions valid when configured', function () {
    config(['razorpay-billing.keep_past_due_active' => true]);

    expect(makeSubscription($this->user, ['status' => SubscriptionStatus::Pending])->valid())->toBeTrue();
});

it('has query scopes', function () {
    makeSubscription($this->user, ['status' => SubscriptionStatus::Active]);
    makeSubscription($this->user, ['status' => SubscriptionStatus::Active, 'ends_at' => '2026-10-01 00:00:00']);
    makeSubscription($this->user, ['status' => SubscriptionStatus::Authenticated, 'trial_ends_at' => '2026-10-30 00:00:00']);
    makeSubscription($this->user, ['status' => SubscriptionStatus::Pending]);
    makeSubscription($this->user, ['status' => SubscriptionStatus::Halted]);
    makeSubscription($this->user, ['status' => SubscriptionStatus::Cancelled]);

    expect(Subscription::query()->active()->count())->toBe(1)
        ->and(Subscription::query()->onTrial()->count())->toBe(1)
        ->and(Subscription::query()->pastDue()->count())->toBe(1)
        ->and(Subscription::query()->halted()->count())->toBe(1)
        ->and(Subscription::query()->cancelled()->count())->toBe(2);
});

it('fills state from a Razorpay subscription entity', function () {
    $subscription = makeSubscription($this->user, ['status' => SubscriptionStatus::Created]);

    $subscription->fillFromRazorpay(fixture('subscriptions/active', ['payment_method' => 'upi', 'quantity' => 2]));

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->quantity)->toBe(2)
        ->and($subscription->payment_method)->toBe('upi')
        ->and($subscription->current_start->getTimestamp())->toBe(1577355871)
        ->and($subscription->current_end->getTimestamp())->toBe(1582655400)
        ->and($subscription->charge_at->getTimestamp())->toBe(1577385991)
        ->and($subscription->ends_at)->toBeNull();
});

it('sets ends_at for terminal states and paused_at while paused', function () {
    $subscription = makeSubscription($this->user);

    $subscription->fillFromRazorpay(['status' => 'paused']);
    expect($subscription->paused_at->equalTo(Carbon::now()))->toBeTrue();

    $subscription->fillFromRazorpay(['status' => 'cancelled', 'ended_at' => 1790000000]);
    expect($subscription->paused_at)->toBeNull()
        ->and($subscription->ends_at->getTimestamp())->toBe(1790000000);
});

it('ignores unknown statuses', function () {
    $subscription = makeSubscription($this->user);

    $subscription->fillFromRazorpay(['status' => 'something_new']);

    expect($subscription->status)->toBe(SubscriptionStatus::Active);
});

it('syncs from Razorpay', function () {
    Http::fake(['api.razorpay.com/v1/subscriptions/sub_x' => Http::response(fixture('subscriptions/active', ['id' => 'sub_x']))]);
    $subscription = makeSubscription($this->user, ['razorpay_id' => 'sub_x', 'status' => SubscriptionStatus::Created]);

    $subscription->syncFromRazorpay();

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
});

it('answers subscribed and onTrial on the billable', function () {
    expect($this->user->subscribed())->toBeFalse();

    makeSubscription($this->user, ['status' => SubscriptionStatus::Active]);

    expect($this->user->subscribed())->toBeTrue()
        ->and($this->user->subscribed('default', 'pro-monthly'))->toBeTrue()
        ->and($this->user->subscribed('default', 'plan_00000000000001'))->toBeTrue()
        ->and($this->user->subscribed('default', 'basic-monthly'))->toBeFalse()
        ->and($this->user->subscribed('other'))->toBeFalse()
        ->and($this->user->onTrial())->toBeFalse();
});

it('uses the latest subscription of a type', function () {
    makeSubscription($this->user, ['status' => SubscriptionStatus::Cancelled]);
    $latest = makeSubscription($this->user, ['status' => SubscriptionStatus::Active]);

    expect($this->user->subscription()->is($latest))->toBeTrue();
});

it('supports a generic trial on the billable', function () {
    $this->user->update(['trial_ends_at' => Carbon::now()->addDays(3)]);

    expect($this->user->onGenericTrial())->toBeTrue()
        ->and($this->user->onTrial())->toBeTrue()
        ->and($this->user->onTrial('default'))->toBeFalse();
});
