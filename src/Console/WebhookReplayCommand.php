<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Console;

use Illuminate\Console\Command;
use Shasha1589\RazorpayBilling\Models\WebhookEvent;
use Shasha1589\RazorpayBilling\Webhooks\WebhookProcessor;
use Throwable;

class WebhookReplayCommand extends Command
{
    protected $signature = 'razorpay:webhook-replay {event_id : The X-Razorpay-Event-Id of a stored webhook}';

    protected $description = 'Reprocess a stored Razorpay webhook event';

    public function handle(WebhookProcessor $processor): int
    {
        $event = WebhookEvent::query()->where('event_id', $this->argument('event_id'))->first();

        if ($event === null) {
            $this->components->error('No stored webhook event with that ID.');

            return self::FAILURE;
        }

        try {
            $processor->process($event);
        } catch (Throwable $e) {
            $this->components->error("Processing {$event->event} failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info("Reprocessed {$event->event}.");

        return self::SUCCESS;
    }
}
