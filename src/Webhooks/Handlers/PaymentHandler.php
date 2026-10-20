<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Webhooks\Handlers;

use Shasha1589\RazorpayBilling\Models\Payment;
use Shasha1589\RazorpayBilling\Models\Subscription;
use Shasha1589\RazorpayBilling\Models\WebhookEvent;
use Shasha1589\RazorpayBilling\Sync\PaymentSync;
use Shasha1589\RazorpayBilling\Webhooks\WebhookHandler;

/**
 * Upserts the payment entity of a payment.* or refund.* webhook. PaymentSync
 * fires PaymentCaptured / PaymentFailed on status changes.
 *
 * @see https://razorpay.com/docs/webhooks/payments
 */
abstract class PaymentHandler implements WebhookHandler
{
    public function __construct(protected readonly PaymentSync $payments) {}

    public function handle(WebhookEvent $event): void
    {
        $this->applyPayment($event);
    }

    protected function applyPayment(WebhookEvent $event): ?Payment
    {
        $entity = $event->entity('payment');

        if ($entity === null) {
            return null;
        }

        // A payment for a subscription charge carries its invoice; link it if
        // the subscription.charged webhook already stored it.
        $existing = is_string($entity['id'] ?? null)
            ? Payment::query()->where('razorpay_payment_id', $entity['id'])->first()
            : null;
        $subscription = $existing?->razorpay_subscription_id === null
            ? null
            : Subscription::query()->where('razorpay_id', $existing->razorpay_subscription_id)->first();

        return $this->payments->apply($entity, $subscription, $event->payload);
    }
}
