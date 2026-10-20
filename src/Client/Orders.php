<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Client;

class Orders
{
    public function __construct(private readonly RazorpayClient $client) {}

    /**
     * `receipt` (max 40 chars) is an idempotency key. Create a new order for
     * every payment attempt.
     *
     * @see https://razorpay.com/docs/api/orders/create
     *
     * @param  array{amount: int, currency: string, receipt?: string, notes?: array<string, string>}  $attributes
     * @return array<string, mixed>
     */
    public function create(array $attributes): array
    {
        return $this->client->post('orders', $attributes);
    }

    /**
     * @see https://razorpay.com/docs/api/orders/fetch-with-id
     *
     * @return array<string, mixed>
     */
    public function fetch(string $id): array
    {
        return $this->client->get('orders/'.rawurlencode($id));
    }
}
