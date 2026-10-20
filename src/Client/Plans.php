<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Client;

use Illuminate\Support\LazyCollection;

class Plans
{
    public function __construct(private readonly RazorpayClient $client) {}

    /**
     * @see https://razorpay.com/docs/api/payments/subscriptions/create-plan
     *
     * @param  array{period: string, interval: int, item: array{name: string, amount: int, currency: string, description?: string}, notes?: array<string, string>}  $attributes
     * @return array<string, mixed>
     */
    public function create(array $attributes): array
    {
        return $this->client->post('plans', $attributes);
    }

    /**
     * @see https://razorpay.com/docs/api/payments/subscriptions/fetch-a-plan
     *
     * @return array<string, mixed>
     */
    public function fetch(string $id): array
    {
        return $this->client->get('plans/'.rawurlencode($id));
    }

    /**
     * One page of plans.
     *
     * @see https://razorpay.com/docs/api/payments/subscriptions/fetch-all-plans
     *
     * @param  array{from?: int, to?: int, count?: int, skip?: int}  $query
     * @return array<string, mixed>
     */
    public function all(array $query = []): array
    {
        return $this->client->get('plans', $query);
    }

    /**
     * Every plan, fetching pages lazily.
     *
     * @see https://razorpay.com/docs/api/payments/subscriptions/fetch-all-plans
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function cursor(): LazyCollection
    {
        return $this->client->paginate('plans');
    }
}
