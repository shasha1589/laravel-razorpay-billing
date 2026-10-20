<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Shasha1589\RazorpayBilling\Client\RazorpayClient;

beforeEach(function () {
    $this->plans = app(RazorpayClient::class)->plans();
});

it('creates a plan', function () {
    Http::fake(['api.razorpay.com/v1/plans' => Http::response(jsonFixture('plans/plan'))]);

    $payload = ['period' => 'monthly', 'interval' => 1, 'item' => ['name' => 'Pro', 'amount' => 1999900, 'currency' => 'INR']];

    expect($this->plans->create($payload)['id'])->toBe('plan_00000000000001');
    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->data() === $payload);
});

it('fetches a plan', function () {
    Http::fake(['api.razorpay.com/v1/plans/plan_00000000000001' => Http::response(jsonFixture('plans/plan'))]);

    expect($this->plans->fetch('plan_00000000000001')['period'])->toBe('monthly');
});

it('lists plans', function () {
    Http::fake(['api.razorpay.com/v1/plans?count=10&skip=0' => Http::response(jsonFixture('plans/collection'))]);

    expect($this->plans->all(['count' => 10, 'skip' => 0])['count'])->toBe(2);
});

it('iterates over all plans', function () {
    Http::fake(['api.razorpay.com/v1/plans*' => Http::response(jsonFixture('plans/collection'))]);

    expect($this->plans->cursor()->pluck('id')->all())->toBe(['plan_00000000000001', 'plan_00000000000002']);
});
