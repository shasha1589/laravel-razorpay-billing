<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Client;

class Payments
{
    public function __construct(private readonly RazorpayClient $client) {}

    /**
     * @see https://razorpay.com/docs/api/payments/fetch-with-id
     *
     * @return array<string, mixed>
     */
    public function fetch(string $id): array
    {
        return $this->client->get('payments/'.rawurlencode($id));
    }

    /**
     * Refunds a captured payment; without `amount` the full payment is refunded.
     *
     * @see https://razorpay.com/docs/api/refunds/create-normal
     *
     * @param  array{amount?: int, speed?: 'normal'|'optimum', notes?: array<string, string>, receipt?: string}  $attributes
     * @return array<string, mixed>
     */
    public function refund(string $id, array $attributes = []): array
    {
        return $this->client->post('payments/'.rawurlencode($id).'/refund', $attributes);
    }
}
