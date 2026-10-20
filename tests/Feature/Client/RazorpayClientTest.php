<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Shasha1589\RazorpayBilling\Client\Exceptions\ApiException;
use Shasha1589\RazorpayBilling\Client\Exceptions\AuthenticationException;
use Shasha1589\RazorpayBilling\Client\Exceptions\BadRequestException;
use Shasha1589\RazorpayBilling\Client\Exceptions\ConnectionException;
use Shasha1589\RazorpayBilling\Client\Exceptions\MissingCredentialsException;
use Shasha1589\RazorpayBilling\Client\Exceptions\NotFoundException;
use Shasha1589\RazorpayBilling\Client\Exceptions\RateLimitException;
use Shasha1589\RazorpayBilling\Client\Exceptions\ServerException;
use Shasha1589\RazorpayBilling\Client\Exceptions\UnexpectedResponseException;
use Shasha1589\RazorpayBilling\Client\RazorpayClient;

beforeEach(function () {
    Sleep::fake();
    $this->client = app(RazorpayClient::class);
});

it('sends authenticated JSON requests to the API base URL', function () {
    Http::fake(['api.razorpay.com/v1/customers' => Http::response(jsonFixture('customers/customer'))]);

    $this->client->post('customers', ['name' => 'Gaurav Kumar']);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.razorpay.com/v1/customers'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('rzp_test_dummykey:dummy_secret'))
        && $request->hasHeader('Accept', 'application/json')
        && $request['name'] === 'Gaurav Kumar');
});

it('is built from config and requires API keys', function () {
    config(['razorpay-billing.key_secret' => null]);
    app()->forgetInstance(RazorpayClient::class);

    app(RazorpayClient::class);
})->throws(MissingCredentialsException::class);

it('uses the configured base URL', function () {
    config(['razorpay-billing.api.base_url' => 'https://example.test/v1/']);
    app()->forgetInstance(RazorpayClient::class);
    Http::fake(['example.test/*' => Http::response(['id' => 'x'])]);

    app(RazorpayClient::class)->get('plans/x');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://example.test/v1/plans/x');
});

it('maps a Razorpay error response to an exception with its details', function () {
    Http::fake(['*' => Http::response(jsonFixture('errors/bad-request'), 400)]);

    try {
        $this->client->post('customers', ['contact' => '1']);
        $this->fail('Expected exception');
    } catch (BadRequestException $e) {
        expect($e->getMessage())->toBe('Contact number should be at least 8 digits, including country code')
            ->and($e->status)->toBe(400)
            ->and($e->errorCode)->toBe('BAD_REQUEST_ERROR')
            ->and($e->field)->toBe('contact')
            ->and($e->reason)->toBe('invalid_contact_number')
            ->and($e->source)->toBe('business')
            ->and($e->step)->toBe('NA')
            ->and($e->metadata)->toBe([]);
    }
});

it('maps HTTP statuses to exception classes', function (int $status, string $class) {
    Http::fake(['*' => Http::response(['error' => ['code' => 'X', 'description' => 'Nope']], $status)]);
    config(['razorpay-billing.api.retries' => 0]);
    app()->forgetInstance(RazorpayClient::class);

    expect(fn () => app(RazorpayClient::class)->get('plans'))->toThrow($class, 'Nope');
})->with([
    [401, AuthenticationException::class],
    [404, NotFoundException::class],
    [429, RateLimitException::class],
    [500, ServerException::class],
    [503, ServerException::class],
    [409, ApiException::class],
]);

it('falls back to a generic message when the error body is not JSON', function () {
    Http::fake(['*' => Http::response('Bad gateway', 502)]);

    expect(fn () => $this->client->post('plans'))->toThrow(ServerException::class, 'Razorpay request failed with HTTP 502.');
});

it('retries GET requests on 5xx with backoff', function () {
    Http::fake(['*' => Http::sequence()->pushStatus(500)->pushStatus(503)->push(['id' => 'plan_1'])]);

    expect($this->client->get('plans/plan_1'))->toBe(['id' => 'plan_1']);

    Http::assertSentCount(3);
    Sleep::assertSleptTimes(2);
});

it('gives up after the configured number of retries', function () {
    Http::fake(['*' => Http::response([], 500)]);

    expect(fn () => $this->client->get('plans'))->toThrow(ServerException::class);

    Http::assertSentCount(3);
});

it('does not retry writes on 5xx', function () {
    Http::fake(['*' => Http::response([], 500)]);

    expect(fn () => $this->client->post('plans', ['period' => 'monthly']))->toThrow(ServerException::class);

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

it('retries any request throttled with 429', function () {
    Http::fake(['*' => Http::sequence()->pushStatus(429)->push(['id' => 'plan_1'])]);

    expect($this->client->post('plans', ['period' => 'monthly']))->toBe(['id' => 'plan_1']);

    Http::assertSentCount(2);
});

it('retries GET requests on connection errors', function () {
    Http::fake(['*' => Http::sequence()->pushFailedConnection()->push(['id' => 'plan_1'])]);

    expect($this->client->get('plans/plan_1'))->toBe(['id' => 'plan_1']);

    Http::assertSentCount(2);
});

it('does not retry writes on connection errors', function () {
    Http::fake(['*' => Http::failedConnection()]);

    expect(fn () => $this->client->post('plans'))->toThrow(ConnectionException::class);

    Http::assertSentCount(1);
});

it('does not retry client errors', function () {
    Http::fake(['*' => Http::response(jsonFixture('errors/bad-request'), 400)]);

    expect(fn () => $this->client->get('plans'))->toThrow(BadRequestException::class);

    Http::assertSentCount(1);
});

it('rejects non-JSON success responses', function () {
    Http::fake(['*' => Http::response('<html></html>')]);

    $this->client->get('plans');
})->throws(UnexpectedResponseException::class);

it('walks every page of a collection', function () {
    $item = fn (int $i) => ['id' => "plan_{$i}"];

    Http::fake([
        'api.razorpay.com/v1/plans?count=100&skip=0' => Http::response(['entity' => 'collection', 'count' => 100, 'items' => array_map($item, range(1, 100))]),
        'api.razorpay.com/v1/plans?count=100&skip=100' => Http::response(['entity' => 'collection', 'count' => 1, 'items' => [$item(101)]]),
    ]);

    $ids = $this->client->paginate('plans')->pluck('id')->all();

    expect($ids)->toHaveCount(101)
        ->and($ids[100])->toBe('plan_101');
    Http::assertSentCount(2);
});
