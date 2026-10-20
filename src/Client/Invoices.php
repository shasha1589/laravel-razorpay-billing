<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Client;

class Invoices
{
    public function __construct(private readonly RazorpayClient $client) {}

    /**
     * Razorpay invoices for a subscription.
     *
     * @see https://razorpay.com/docs/api/payments/subscriptions/fetch-invoices
     *
     * @return array<string, mixed>
     */
    public function forSubscription(string $subscriptionId): array
    {
        return $this->client->get('invoices', ['subscription_id' => $subscriptionId]);
    }

    /**
     * @see https://razorpay.com/docs/api/payments/invoices/fetch-with-id
     *
     * @return array<string, mixed>
     */
    public function fetch(string $id): array
    {
        return $this->client->get('invoices/'.rawurlencode($id));
    }
}
