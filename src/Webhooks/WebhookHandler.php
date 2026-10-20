<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Webhooks;

use Shasha1589\RazorpayBilling\Models\WebhookEvent;

/**
 * Handles one Razorpay webhook event. Implementations must be idempotent:
 * the same event may be delivered more than once, and out of order.
 */
interface WebhookHandler
{
    public function handle(WebhookEvent $event): void;
}
