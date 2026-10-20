<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Client;

use Illuminate\Support\LazyCollection;

class Customers
{
    public function __construct(private readonly RazorpayClient $client) {}

    /**
     * `fail_existing` "0" returns the existing customer instead of a 400 when
     * one with the same details exists.
     *
     * @see https://razorpay.com/docs/api/customers/create
     *
     * @param  array{name?: string, email?: string, contact?: string, gstin?: string, fail_existing?: '0'|'1', notes?: array<string, string>}  $attributes
     * @return array<string, mixed>
     */
    public function create(array $attributes): array
    {
        return $this->client->post('customers', $attributes);
    }

    /**
     * @see https://razorpay.com/docs/api/customers/fetch-with-id
     *
     * @return array<string, mixed>
     */
    public function fetch(string $id): array
    {
        return $this->client->get('customers/'.rawurlencode($id));
    }

    /**
     * @see https://razorpay.com/docs/api/customers/edit
     *
     * @param  array{name?: string, email?: string, contact?: string}  $attributes
     * @return array<string, mixed>
     */
    public function update(string $id, array $attributes): array
    {
        return $this->client->put('customers/'.rawurlencode($id), $attributes);
    }

    /**
     * One page of customers.
     *
     * @see https://razorpay.com/docs/api/customers/fetch-all
     *
     * @param  array{count?: int, skip?: int}  $query
     * @return array<string, mixed>
     */
    public function all(array $query = []): array
    {
        return $this->client->get('customers', $query);
    }

    /**
     * Every customer, fetching pages lazily.
     *
     * @see https://razorpay.com/docs/api/customers/fetch-all
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function cursor(): LazyCollection
    {
        return $this->client->paginate('customers');
    }
}
