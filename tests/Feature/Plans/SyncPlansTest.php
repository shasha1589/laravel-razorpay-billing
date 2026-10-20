<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Shasha1589\RazorpayBilling\Plans\InvalidPlanException;
use Shasha1589\RazorpayBilling\Plans\PlanRepository;

beforeEach(function () {
    config(['razorpay-billing.plans' => [
        'pro-monthly' => ['name' => 'Pro', 'period' => 'monthly', 'interval' => 1, 'amount' => 1999900],
    ]]);
});

function remotePlans(array ...$plans): array
{
    return ['entity' => 'collection', 'count' => count($plans), 'items' => $plans];
}

function fakePlansApi(array $collection): void
{
    Http::fake(function (Request $request) use ($collection) {
        return $request->method() === 'GET'
            ? Http::response($collection)
            : Http::response(fixture('plans/plan', ['id' => 'plan_new']));
    });
}

it('creates plans that do not exist in Razorpay', function () {
    fakePlansApi(fixture('plans/collection'));

    $this->artisan('razorpay:sync-plans')
        ->expectsTable(['Plan key', 'Status', 'Razorpay plan ID'], [['pro-monthly', 'created', 'plan_new']])
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://api.razorpay.com/v1/plans'
        && $request['notes'] === ['plan_key' => 'pro-monthly']
        && $request['item']['amount'] === 1999900);
    expect(app(PlanRepository::class)->razorpayId('pro-monthly'))->toBe('plan_new');
});

it('leaves existing matching plans alone', function () {
    fakePlansApi(remotePlans(fixture('plans/plan')));

    $this->artisan('razorpay:sync-plans')
        ->expectsTable(['Plan key', 'Status', 'Razorpay plan ID'], [['pro-monthly', 'exists', 'plan_00000000000001']])
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

it('reports a conflict instead of modifying a plan whose price changed', function () {
    fakePlansApi(remotePlans(fixture('plans/plan', ['item' => ['amount' => 999900]])));

    $this->artisan('razorpay:sync-plans')
        ->expectsTable(['Plan key', 'Status', 'Razorpay plan ID'], [['pro-monthly', 'conflict', 'plan_00000000000001']])
        ->assertFailed();

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    expect(app(PlanRepository::class)->razorpayId('pro-monthly'))->toBeNull();
});

it('creates nothing on a dry run', function () {
    fakePlansApi(remotePlans());

    $this->artisan('razorpay:sync-plans --dry-run')
        ->expectsTable(['Plan key', 'Status', 'Razorpay plan ID'], [['pro-monthly', 'would create', '-']])
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

it('is safe to run twice', function () {
    $created = [];
    Http::fake(function (Request $request) use (&$created) {
        if ($request->method() === 'POST') {
            return Http::response($created[] = fixture('plans/plan', ['id' => 'plan_new']));
        }

        return Http::response(remotePlans(...$created));
    });

    $this->artisan('razorpay:sync-plans')->assertSuccessful();
    Cache::flush();

    $this->artisan('razorpay:sync-plans')
        ->expectsTable(['Plan key', 'Status', 'Razorpay plan ID'], [['pro-monthly', 'exists', 'plan_new']])
        ->assertSuccessful();

    expect($created)->toHaveCount(1);
});

it('warns when no plans are configured', function () {
    config(['razorpay-billing.plans' => []]);
    Http::fake();

    $this->artisan('razorpay:sync-plans')
        ->expectsOutputToContain('No plans are defined')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('resolves plan IDs from cache without calling Razorpay', function () {
    Cache::forever('razorpay-billing:plan:pro-monthly', 'plan_cached');
    Http::fake();

    expect(app(PlanRepository::class)->razorpayId('pro-monthly'))->toBe('plan_cached');
    Http::assertNothingSent();
});

it('rebuilds the plan ID map from Razorpay when the cache is empty', function () {
    fakePlansApi(remotePlans(fixture('plans/plan')));

    expect(app(PlanRepository::class)->razorpayId('pro-monthly'))->toBe('plan_00000000000001')
        ->and(Cache::get('razorpay-billing:plan:pro-monthly'))->toBe('plan_00000000000001');
});

it('returns null for a plan that was never synced', function () {
    fakePlansApi(remotePlans());

    expect(app(PlanRepository::class)->razorpayId('pro-monthly'))->toBeNull();
});

it('throws for an unknown plan key', function () {
    app(PlanRepository::class)->razorpayId('missing');
})->throws(InvalidPlanException::class, 'Plan [missing] is not defined');
