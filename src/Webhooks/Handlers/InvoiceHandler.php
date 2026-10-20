<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Webhooks\Handlers;

use Shasha1589\RazorpayBilling\Events\InvoiceEvent;
use Shasha1589\RazorpayBilling\Models\Subscription;
use Shasha1589\RazorpayBilling\Models\WebhookEvent;
use Shasha1589\RazorpayBilling\Webhooks\WebhookHandler;

/**
 * Razorpay invoices are not stored locally; the event mirrors their status to
 * the app. Duplicate deliveries are filtered by the webhook event store.
 *
 * @see https://razorpay.com/docs/webhooks/invoices
 */
abstract class InvoiceHandler implements WebhookHandler
{
    /**
     * @return class-string<InvoiceEvent>
     */
    abstract protected function event(): string;

    public function handle(WebhookEvent $event): void
    {
        $invoice = $event->entity('invoice');

        if ($invoice === null) {
            return;
        }

        $subscriptionId = $invoice['subscription_id'] ?? null;
        $subscription = is_string($subscriptionId)
            ? Subscription::query()->where('razorpay_id', $subscriptionId)->first()
            : null;

        $this->event()::dispatch($subscription?->billable, $subscription, $invoice, $event->payload);
    }
}
