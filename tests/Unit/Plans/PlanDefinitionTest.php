<?php

declare(strict_types=1);

use Shasha1589\RazorpayBilling\Plans\InvalidPlanException;
use Shasha1589\RazorpayBilling\Plans\PlanDefinition;
use Shasha1589\RazorpayBilling\Plans\PlanPeriod;

function proPlan(array $overrides = []): array
{
    return [...['name' => 'Pro', 'period' => 'monthly', 'interval' => 1, 'amount' => 1999900], ...$overrides];
}

it('builds a definition from config with defaults', function () {
    $plan = PlanDefinition::fromConfig('pro-monthly', proPlan(), 'INR');

    expect($plan->key)->toBe('pro-monthly')
        ->and($plan->period)->toBe(PlanPeriod::Monthly)
        ->and($plan->currency)->toBe('INR')
        ->and($plan->methods)->toBe(['card', 'emandate', 'upi'])
        ->and($plan->notes)->toBe([]);
});

it('builds the create-plan payload tagged with the plan key', function () {
    $plan = PlanDefinition::fromConfig('pro-monthly', proPlan(['description' => 'Pro plan', 'notes' => ['tier' => 'pro']]), 'INR');

    expect($plan->toRazorpayPayload())->toBe([
        'period' => 'monthly',
        'interval' => 1,
        'item' => ['name' => 'Pro', 'amount' => 1999900, 'currency' => 'INR', 'description' => 'Pro plan'],
        'notes' => ['tier' => 'pro', 'plan_key' => 'pro-monthly'],
    ]);
});

it('never sends methods to Razorpay', function () {
    $payload = PlanDefinition::fromConfig('pro-monthly', proPlan(['methods' => ['card']]), 'INR')->toRazorpayPayload();

    expect($payload)->not->toHaveKey('methods');
});

it('matches a Razorpay plan on billing fields only', function () {
    $plan = PlanDefinition::fromConfig('pro-monthly', proPlan(), 'INR');
    $remote = jsonFixture('plans/plan');

    expect($plan->matches($remote))->toBeTrue()
        ->and($plan->matches(jsonFixture('plans/plan', ['item' => ['name' => 'Renamed']])))->toBeTrue()
        ->and($plan->matches(jsonFixture('plans/plan', ['item' => ['amount' => 2999900]])))->toBeFalse()
        ->and($plan->matches(jsonFixture('plans/plan', ['period' => 'yearly'])))->toBeFalse()
        ->and($plan->matches(jsonFixture('plans/plan', ['interval' => 3])))->toBeFalse()
        ->and($plan->matches(jsonFixture('plans/plan', ['item' => ['currency' => 'USD']])))->toBeFalse();
});

it('rejects invalid plan config', function (array $config, string $message) {
    expect(fn () => PlanDefinition::fromConfig('bad', $config, 'INR'))->toThrow(InvalidPlanException::class, $message);
})->with([
    'missing name' => [proPlan(['name' => null]), 'name is required'],
    'unknown period' => [proPlan(['period' => 'hourly']), 'period must be one of'],
    'float amount' => [proPlan(['amount' => 199.99]), 'amount must be an integer'],
    'zero amount' => [proPlan(['amount' => 0]), 'amount must be a positive integer'],
    'zero interval' => [proPlan(['interval' => 0]), 'interval for a monthly plan must be at least 1'],
    'short daily interval' => [proPlan(['period' => 'daily', 'interval' => 6]), 'interval for a daily plan must be at least 7'],
    'unknown method' => [proPlan(['methods' => ['card', 'netbanking']]), 'methods must be a non-empty subset'],
    'no methods' => [proPlan(['methods' => []]), 'methods must be a non-empty subset'],
    'non-string note' => [proPlan(['notes' => ['a' => 1]]), 'notes must be an array of string keys'],
    'too many notes' => [proPlan(['notes' => array_combine(array_map(fn ($i) => "k{$i}", range(1, 15)), array_fill(0, 15, 'v'))]), 'at most 14 notes'],
]);

it('accepts a 7-day daily plan', function () {
    expect(PlanDefinition::fromConfig('weekly', proPlan(['period' => 'daily', 'interval' => 7]), 'INR')->interval)->toBe(7);
});
