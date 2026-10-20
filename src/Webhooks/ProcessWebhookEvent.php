<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Webhooks;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Shasha1589\RazorpayBilling\Models\WebhookEvent;

class ProcessWebhookEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public readonly int $webhookEventId) {}

    public function handle(WebhookProcessor $processor): void
    {
        $event = WebhookEvent::query()->find($this->webhookEventId);

        if ($event === null || ($event->processed_at !== null && config('razorpay-billing.webhook_tolerance_events') === true)) {
            return;
        }

        $processor->process($event);
    }
}
