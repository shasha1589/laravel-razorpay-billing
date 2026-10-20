<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Webhooks\Handlers;

use Shasha1589\RazorpayBilling\Events\SubscriptionEvent;
use Shasha1589\RazorpayBilling\Models\Subscription;
use Shasha1589\RazorpayBilling\Models\WebhookEvent;
use Shasha1589\RazorpayBilling\Webhooks\WebhookHandler;

/**
 * Token webhooks don't reference the subscription, so it is found by the
 * token ID recorded from its payments. Unmatched tokens are ignored (the app
 * still gets WebhookReceived).
 *
 * @see https://razorpay.com/docs/api/payments/recurring-payments/webhooks
 */
abstract class TokenHandler implements WebhookHandler
{
    /**
     * @return class-string<SubscriptionEvent>
     */
    abstract protected function event(): string;

    abstract protected function defaultStatus(): string;

    public function handle(WebhookEvent $event): void
    {
        $token = $event->entity('token');
        $tokenId = $token['id'] ?? null;

        if ($token === null || ! is_string($tokenId)) {
            return;
        }

        $details = is_array($token['recurring_details'] ?? null) ? $token['recurring_details'] : [];
        $status = is_string($details['status'] ?? null) ? $details['status'] : $this->defaultStatus();

        Subscription::query()->where('razorpay_token_id', $tokenId)->get()->each(function (Subscription $subscription) use ($status, $event) {
            if ($subscription->mandate_status === $status) {
                return;
            }

            $subscription->mandate_status = $status;
            $subscription->save();

            $this->event()::dispatch($subscription->billable, $subscription, $event->payload);
        });
    }
}
