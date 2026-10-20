<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Shasha1589\RazorpayBilling\Checkout;
use Shasha1589\RazorpayBilling\Enums\SubscriptionStatus;
use Shasha1589\RazorpayBilling\Exceptions\SubscriptionCreationFailed;
use Shasha1589\RazorpayBilling\Tests\Support\User;

beforeEach(function () {
    Carbon::setTestNow('2026-10-20 10:00:00');

    config(['razorpay-billing.plans' => [
        'pro-monthly' => ['name' => 'Pro', 'period' => 'monthly', 'interval' => 1, 'amount' => 1999900, 'total_count' => 120],
        'basic-monthly' => ['name' => 'Basic', 'period' => 'monthly', 'interval' => 1, 'amount' => 49900, 'methods' => ['upi', 'card']],
        'card-only' => ['name' => 'Card', 'period' => 'monthly', 'interval' => 1, 'amount' => 49900, 'methods' => ['card']],
    ]]);
    Cache::forever('razorpay-billing:plan:pro-monthly', 'plan_00000000000001');
    Cache::forever('razorpay-billing:plan:basic-monthly', 'plan_00000000000002');
    Cache::forever('razorpay-billing:plan:card-only', 'plan_00000000000003');

    $this->user = User::create(['name' => 'Gaurav Kumar', 'email' => 'gaurav.kumar@example.com']);

    Http::fake(['api.razorpay.com/v1/subscriptions' => Http::response(fixture('subscriptions/created'))]);
});

it('creates a Razorpay subscription and a local row in created status', function () {
    $checkout = $this->user->newSubscription('default', 'pro-monthly')
        ->notes(['source' => 'pricing-page'])
        ->notifyCustomer(false)
        ->create();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.razorpay.com/v1/subscriptions'
        && $request->data() === [
            'plan_id' => 'plan_00000000000001',
            'total_count' => 120,
            'quantity' => 1,
            'customer_notify' => false,
            'notes' => [
                'source' => 'pricing-page',
                'billable_type' => User::class,
                'billable_id' => (string) $this->user->id,
                'subscription_type' => 'default',
            ],
        ]);

    $subscription = $this->user->subscription();

    expect($checkout)->toBeInstanceOf(Checkout::class)
        ->and($checkout->subscriptionId())->toBe('sub_00000000000001')
        ->and($checkout->shortUrl())->toBe('https://rzp.io/rzp/Dqdqx3h')
        ->and($checkout->options()['prefill'])->toBe(['name' => 'Gaurav Kumar', 'email' => 'gaurav.kumar@example.com'])
        ->and($subscription->razorpay_id)->toBe('sub_00000000000001')
        ->and($subscription->razorpay_plan_id)->toBe('plan_00000000000001')
        ->and($subscription->plan_key)->toBe('pro-monthly')
        ->and($subscription->status)->toBe(SubscriptionStatus::Created)
        ->and($subscription->notes)->toBe(['source' => 'pricing-page'])
        ->and($subscription->charge_at->getTimestamp())->toBe(1773461489);
});

it('maps a trial to start_at', function () {
    $this->user->newSubscription('default', 'pro-monthly')->trialDays(14)->create();

    $trialEnd = Carbon::now()->addDays(14);

    Http::assertSent(fn (Request $request) => $request['start_at'] === $trialEnd->getTimestamp());
    expect($this->user->subscription()->trial_ends_at->equalTo($trialEnd))->toBeTrue();
});

it('sends quantity, total count and expire_by', function () {
    $this->user->newSubscription('default', 'pro-monthly')
        ->quantity(3)
        ->totalCount(12)
        ->expireBy(Carbon::now()->addDay())
        ->create();

    Http::assertSent(fn (Request $request) => $request['quantity'] === 3
        && $request['total_count'] === 12
        && $request['expire_by'] === Carbon::now()->addDay()->getTimestamp());
    expect($this->user->subscription()->quantity)->toBe(1); // Razorpay's response wins
});

it('accepts a Razorpay plan ID directly', function () {
    $this->user->newSubscription('default', 'plan_RAW00000000001')->totalCount(6)->methods(['card'])->create();

    Http::assertSent(fn (Request $request) => $request['plan_id'] === 'plan_RAW00000000001');
    expect($this->user->subscription()->plan_key)->toBeNull();
});

it('requires a total count', function () {
    $this->user->newSubscription('default', 'basic-monthly')->create();
})->throws(SubscriptionCreationFailed::class, 'totalCount()');

it('requires the plan to be synced', function () {
    Cache::forget('razorpay-billing:plan:pro-monthly');
    Http::fake(['api.razorpay.com/v1/plans*' => Http::response(['entity' => 'collection', 'count' => 0, 'items' => []])]);

    $this->user->newSubscription('default', 'pro-monthly')->create();
})->throws(SubscriptionCreationFailed::class, 'razorpay:sync-plans');

it('refuses a UPI-only checkout above the UPI AutoPay limit', function () {
    $this->user->newSubscription('default', 'pro-monthly')->methods(['upi'])->create();
})->throws(SubscriptionCreationFailed::class, 'UPI AutoPay is limited to 1500000 paise');

it('counts quantity towards the UPI limit', function () {
    $this->user->newSubscription('default', 'basic-monthly')->totalCount(12)->quantity(31)->methods(['upi'])->create();
})->throws(SubscriptionCreationFailed::class, 'charges 1546900 paise');

it('allows a UPI-only checkout at or below the limit', function () {
    $checkout = $this->user->newSubscription('default', 'basic-monthly')->totalCount(12)->methods(['upi'])->create();

    expect($checkout->methods())->toBe(['upi'])
        ->and($checkout->options()['config']['display']['hide'])->toBe([['method' => 'card']]);
});

it('drops UPI from mixed methods above the limit', function () {
    $checkout = $this->user->newSubscription('default', 'pro-monthly')->create();

    expect($checkout->methods())->toBe(['card', 'emandate'])
        ->and($checkout->options()['config']['display']['hide'])->toBe([['method' => 'upi']]);
});

it('checks the UPI limit against a raw plan ID by fetching the plan', function () {
    Http::fake(['api.razorpay.com/v1/plans/plan_RAW00000000001' => Http::response(fixture('plans/plan', ['id' => 'plan_RAW00000000001']))]);

    $this->user->newSubscription('default', 'plan_RAW00000000001')->totalCount(6)->methods(['upi'])->create();
})->throws(SubscriptionCreationFailed::class, 'UPI AutoPay');

it('refuses methods the plan does not allow', function () {
    $this->user->newSubscription('default', 'card-only')->totalCount(6)->methods(['card', 'upi'])->create();
})->throws(SubscriptionCreationFailed::class, 'not allowed for this plan: upi');

it('limits notes so Razorpay accepts them', function () {
    $notes = array_combine(array_map(fn ($i) => "k{$i}", range(1, 13)), array_fill(0, 13, 'v'));

    $this->user->newSubscription('default', 'pro-monthly')->notes($notes);
})->throws(SubscriptionCreationFailed::class, 'at most 12 notes');
