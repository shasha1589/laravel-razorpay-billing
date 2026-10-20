<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Shasha1589\RazorpayBilling\Exceptions\CustomerAlreadyCreated;
use Shasha1589\RazorpayBilling\Exceptions\InvalidCustomer;
use Shasha1589\RazorpayBilling\Tests\Support\User;

beforeEach(function () {
    $this->user = User::create(['name' => 'Gaurav Kumar', 'email' => 'gaurav.kumar@example.com', 'phone' => '9123456780']);
});

it('creates a Razorpay customer from the billable', function () {
    Http::fake(['api.razorpay.com/v1/customers' => Http::response(jsonFixture('customers/customer'))]);

    expect($this->user->createAsRazorpayCustomer(['notes' => ['team' => 'a']]))->toBe('cust_1Aa00000000001')
        ->and($this->user->fresh()->razorpay_customer_id)->toBe('cust_1Aa00000000001');

    Http::assertSent(fn (Request $request) => $request->data() === [
        'name' => 'Gaurav Kumar',
        'email' => 'gaurav.kumar@example.com',
        'contact' => '9123456780',
        'fail_existing' => '0',
        'notes' => ['team' => 'a'],
    ]);
});

it('sends the billing GSTIN when set', function () {
    Http::fake(['*' => Http::response(jsonFixture('customers/customer'))]);
    $this->user->forceFill(['billing_gstin' => '29ABCDE1234F1Z5'])->save();

    $this->user->createAsRazorpayCustomer();

    Http::assertSent(fn (Request $request) => $request['gstin'] === '29ABCDE1234F1Z5');
});

it('refuses to create a second customer', function () {
    $this->user->forceFill(['razorpay_customer_id' => 'cust_existing'])->save();

    $this->user->createAsRazorpayCustomer();
})->throws(CustomerAlreadyCreated::class);

it('returns the existing customer ID without calling Razorpay', function () {
    Http::fake();
    $this->user->forceFill(['razorpay_customer_id' => 'cust_existing'])->save();

    expect($this->user->createOrGetRazorpayCustomer())->toBe('cust_existing');
    Http::assertNothingSent();
});

it('creates the customer when missing', function () {
    Http::fake(['*' => Http::response(jsonFixture('customers/customer'))]);

    expect($this->user->createOrGetRazorpayCustomer())->toBe('cust_1Aa00000000001');
});

it('updates the Razorpay customer', function () {
    Http::fake(['api.razorpay.com/v1/customers/cust_1' => Http::response(jsonFixture('customers/customer'))]);
    $this->user->forceFill(['razorpay_customer_id' => 'cust_1'])->save();

    $this->user->updateRazorpayCustomer(['name' => 'New Name']);

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request->data() === ['name' => 'New Name']);
});

it('requires a customer before updating', function () {
    $this->user->updateRazorpayCustomer(['name' => 'x']);
})->throws(InvalidCustomer::class);
