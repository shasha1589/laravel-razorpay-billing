<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Shasha1589\RazorpayBilling\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature', 'Integration');

// Unit and Feature tests must never reach the real Razorpay API.
uses()->beforeEach(fn () => Http::preventStrayRequests())->in('Unit', 'Feature');

/**
 * Loads tests/Fixtures/{name}.json without its `_doc` source URL.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function jsonFixture(string $name, array $overrides = []): array
{
    $data = json_decode((string) file_get_contents(__DIR__."/Fixtures/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);
    unset($data['_doc']);

    return array_replace_recursive($data, $overrides);
}

/**
 * A subscription.* webhook built from the documented subscription.charged
 * sample, without its payment.
 *
 * @param  array<string, mixed>  $entity  Overrides for the subscription entity.
 * @return array<string, mixed>
 */
function subscriptionWebhook(string $event, string $status, array $entity = [], int $createdAt = 1567690383): array
{
    $payload = jsonFixture('webhooks/subscription.charged');
    unset($payload['payload']['payment']);
    $payload['event'] = $event;
    $payload['contains'] = ['subscription'];
    $payload['created_at'] = $createdAt;
    $payload['payload']['subscription']['entity'] = array_replace_recursive(
        $payload['payload']['subscription']['entity'],
        ['status' => $status],
        $entity,
    );

    return $payload;
}
