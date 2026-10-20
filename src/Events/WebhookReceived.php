<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Shasha1589\RazorpayBilling\Models\WebhookEvent;

/**
 * Fired for every webhook processed, including events this package doesn't
 * handle, so apps can react to any Razorpay event.
 */
class WebhookReceived
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $payload  Raw webhook body.
     */
    public function __construct(
        public readonly WebhookEvent $webhookEvent,
        public readonly array $payload,
    ) {}
}
