<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Webhooks\Handlers;

use Shasha1589\RazorpayBilling\Models\Subscription;
use Shasha1589\RazorpayBilling\Models\WebhookEvent;
use Shasha1589\RazorpayBilling\Sync\SubscriptionSync;
use Shasha1589\RazorpayBilling\Webhooks\WebhookHandler;

/**
 * Applies the subscription entity carried by a subscription.* webhook. Events
 * are fired by SubscriptionSync when the state actually changes.
 *
 * @see https://razorpay.com/docs/webhooks/subscriptions
 */
abstract class SubscriptionStateHandler implements WebhookHandler
{
    public function __construct(protected readonly SubscriptionSync $sync) {}

    public function handle(WebhookEvent $event): void
    {
        $this->applySubscription($event);
    }

    protected function applySubscription(WebhookEvent $event): ?Subscription
    {
        $entity = $event->entity('subscription');
        $subscription = $entity === null ? null : $this->sync->resolve($entity);

        if ($entity === null || $subscription === null) {
            return null;
        }

        $this->sync->apply($subscription, $entity, $event->payload, $event->occurredAt());

        return $subscription;
    }
}
