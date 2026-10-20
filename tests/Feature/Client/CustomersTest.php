<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Shasha1589\RazorpayBilling\Client\RazorpayClient;

beforeEach(function () {
    $this->customers = app(RazorpayClient::class)->customers();
});

it('creates a customer', function () {
    Http::fake(['api.razorpay.com/v1/customers' => Http::response(jsonFixture('customers/customer'))]);

    $customer = $this->customers->create(['name' => 'Gaurav Kumar', 'email' => 'gaurav.kumar@example.com', 'fail_existing' => '0']);

    expect($customer['id'])->toBe('cust_1Aa00000000001');
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->data() === ['name' => 'Gaurav Kumar', 'email' => 'gaurav.kumar@example.com', 'fail_existing' => '0']);
});

it('fetches a customer', function () {
    Http::fake(['api.razorpay.com/v1/customers/cust_1Aa00000000001' => Http::response(jsonFixture('customers/customer'))]);

    expect($this->customers->fetch('cust_1Aa00000000001')['email'])->toBe('gaurav.kumar@example.com');
});

it('updates a customer with PUT', function () {
    Http::fake(['api.razorpay.com/v1/customers/cust_1Aa00000000001' => Http::response(jsonFixture('customers/customer', ['name' => 'New Name']))]);

    expect($this->customers->update('cust_1Aa00000000001', ['name' => 'New Name'])['name'])->toBe('New Name');
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request['name'] === 'New Name');
});

it('lists customers', function () {
    Http::fake(['api.razorpay.com/v1/customers?count=2' => Http::response(jsonFixture('customers/collection'))]);

    expect($this->customers->all(['count' => 2])['items'])->toHaveCount(2);
});

it('iterates over all customers', function () {
    Http::fake(['api.razorpay.com/v1/customers*' => Http::response(jsonFixture('customers/collection'))]);

    expect($this->customers->cursor()->pluck('id')->all())->toBe(['cust_LQPdeJqQeKQrJM', 'cust_LQPd9lomgwDE5F']);
});

it('encodes IDs in paths', function () {
    Http::fake(['*' => Http::response(jsonFixture('customers/customer'))]);

    $this->customers->fetch('cust_1/../plans');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.razorpay.com/v1/customers/cust_1%2F..%2Fplans');
});
