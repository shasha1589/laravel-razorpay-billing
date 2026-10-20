<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Builders;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Shasha1589\RazorpayBilling\Checkout;
use Shasha1589\RazorpayBilling\Client\Exceptions\UnexpectedResponseException;
use Shasha1589\RazorpayBilling\Client\RazorpayClient;
use Shasha1589\RazorpayBilling\Invoices\GstCalculator;

/**
 * Creates a Razorpay order for a one-time charge. The order's notes identify
 * the billable, so payment webhooks can be linked to it.
 */
class OrderBuilder
{
    /** Razorpay's minimum order amount in paise (₹1). */
    public const MIN_AMOUNT = 100;

    /** Razorpay allows 15 notes; two identify the billable. */
    public const MAX_NOTES = 13;

    /**
     * @param  array{name?: string, email?: string, contact?: string}  $prefill
     */
    public function __construct(
        private readonly Model $owner,
        private readonly array $prefill = [],
    ) {}

    /**
     * @see https://razorpay.com/docs/api/orders/create
     *
     * @param  array{currency?: string, receipt?: string, notes?: array<string, string>, description?: string}  $options
     */
    public function create(int $amount, array $options = []): Checkout
    {
        if ($amount < self::MIN_AMOUNT) {
            throw new InvalidArgumentException('Charge amount must be at least '.self::MIN_AMOUNT.' paise.');
        }

        $notes = $options['notes'] ?? [];

        if (count($notes) > self::MAX_NOTES) {
            throw new InvalidArgumentException('A charge can have at most '.self::MAX_NOTES.' notes.');
        }

        if (isset($options['receipt']) && mb_strlen($options['receipt']) > 40) {
            throw new InvalidArgumentException('Receipt must be at most 40 characters.');
        }

        $key = $this->owner->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new \LogicException('The billable model must be saved before charging.');
        }

        $defaultCurrency = config('razorpay-billing.currency');
        $currency = $options['currency'] ?? (is_string($defaultCurrency) ? $defaultCurrency : 'INR');
        $client = app(RazorpayClient::class);
        $amount = GstCalculator::fromConfig(app('config'))->chargeAmount($amount);

        $payload = [
            'amount' => $amount,
            'currency' => $currency,
            'notes' => [
                ...$notes,
                'billable_type' => $this->owner->getMorphClass(),
                'billable_id' => (string) $key,
            ],
        ];

        if (isset($options['receipt'])) {
            $payload['receipt'] = $options['receipt'];
        }

        $order = $client->orders()->create($payload);
        $orderId = $order['id'] ?? null;

        if (! is_string($orderId)) {
            throw new UnexpectedResponseException('Razorpay returned an order without an id.');
        }

        $checkout = new Checkout(
            keyId: $client->keyId(),
            orderId: $orderId,
            prefill: $this->prefill,
            notes: $notes,
            amount: $amount,
            currency: $currency,
        );

        if (isset($options['description'])) {
            $checkout->description($options['description']);
        }

        return $checkout;
    }
}
