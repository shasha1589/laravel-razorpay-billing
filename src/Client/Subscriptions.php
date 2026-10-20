<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Client;

class Subscriptions
{
    public function __construct(private readonly RazorpayClient $client) {}

    /**
     * @see https://razorpay.com/docs/api/payments/subscriptions/create-subscription
     *
     * @param  array{plan_id: string, total_count: int, quantity?: int, start_at?: int, expire_by?: int, customer_notify?: bool, notes?: array<string, string>}  $attributes
     * @return array<string, mixed>
     */
    public function create(array $attributes): array
    {
        return $this->client->post('subscriptions', $attributes);
    }

    /**
     * @see https://razorpay.com/docs/api/payments/subscriptions/fetch-subscription-id
     *
     * @return array<string, mixed>
     */
    public function fetch(string $id): array
    {
        return $this->client->get('subscriptions/'.rawurlencode($id));
    }

    /**
     * Not allowed for UPI or eMandate subscriptions, or in `created` status.
     *
     * @see https://razorpay.com/docs/api/payments/subscriptions/update-subscription
     *
     * @param  array{plan_id?: string, quantity?: int, remaining_count?: int, schedule_change_at?: 'now'|'cycle_end', customer_notify?: bool}  $attributes
     * @return array<string, mixed>
     */
    public function update(string $id, array $attributes): array
    {
        return $this->client->patch('subscriptions/'.rawurlencode($id), $attributes);
    }

    /**
     * @see https://razorpay.com/docs/api/payments/subscriptions/cancel-subscription
     *
     * @return array<string, mixed>
     */
    public function cancel(string $id, bool $atCycleEnd = false): array
    {
        return $this->client->post('subscriptions/'.rawurlencode($id).'/cancel', ['cancel_at_cycle_end' => $atCycleEnd]);
    }

    /**
     * Only active subscriptions can be paused.
     *
     * @see https://razorpay.com/docs/api/payments/subscriptions/pause-subscription
     *
     * @return array<string, mixed>
     */
    public function pause(string $id): array
    {
        return $this->client->post('subscriptions/'.rawurlencode($id).'/pause', ['pause_at' => 'now']);
    }

    /**
     * @see https://razorpay.com/docs/api/payments/subscriptions/resume-subscription
     *
     * @return array<string, mixed>
     */
    public function resume(string $id): array
    {
        return $this->client->post('subscriptions/'.rawurlencode($id).'/resume', ['resume_at' => 'now']);
    }

    /**
     * Adds a one-time charge to the next invoice.
     *
     * @see https://razorpay.com/docs/api/payments/subscriptions/#create-an-add-on
     * @see https://github.com/razorpay/razorpay-php/blob/master/documents/addon.md
     *
     * @param  array{item: array{name: string, amount: int, currency: string, description?: string}, quantity?: int}  $attributes
     * @return array<string, mixed>
     */
    public function createAddon(string $id, array $attributes): array
    {
        return $this->client->post('subscriptions/'.rawurlencode($id).'/addons', $attributes);
    }
}
