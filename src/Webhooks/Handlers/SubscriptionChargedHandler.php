<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Webhooks\Handlers;

use Shasha1589\RazorpayBilling\Enums\PaymentStatus;
use Shasha1589\RazorpayBilling\Events\SubscriptionCharged;
use Shasha1589\RazorpayBilling\Models\WebhookEvent;
use Shasha1589\RazorpayBilling\Sync\PaymentSync;
use Shasha1589\RazorpayBilling\Sync\SubscriptionSync;

/**
 * subscription.charged: updates periods, upserts the payment and fires
 * SubscriptionCharged once per captured payment.
 *
 * @see https://razorpay.com/docs/webhooks/subscriptions
 */
class SubscriptionChargedHandler extends SubscriptionStateHandler
{
    public function __construct(SubscriptionSync $sync, private readonly PaymentSync $payments)
    {
        parent::__construct($sync);
    }

    public function handle(WebhookEvent $event): void
    {
        $subscription = $this->applySubscription($event);
        $entity = $event->entity('payment');

        if ($subscription === null || $entity === null) {
            return;
        }

        $payment = $this->payments->apply($entity, $subscription, $event->payload);

        if ($payment !== null && $payment->status === PaymentStatus::Captured && ($payment->wasRecentlyCreated || $payment->wasChanged('status'))) {
            SubscriptionCharged::dispatch($subscription->billable, $subscription, $payment, $event->payload);
        }
    }
}
