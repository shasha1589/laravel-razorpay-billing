<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Http\Controllers;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Shasha1589\RazorpayBilling\Models\WebhookEvent;
use Shasha1589\RazorpayBilling\Webhooks\ProcessWebhookEvent;

/**
 * Stores the event (deduplicated by X-Razorpay-Event-Id), hands it to a queued
 * job and answers 200 quickly. The signature is checked by middleware.
 *
 * @see https://razorpay.com/docs/webhooks/validate-test
 */
class WebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload) || ! is_string($payload['event'] ?? null)) {
            return response()->json(['message' => 'Malformed webhook payload.'], 400);
        }

        $header = $request->header('X-Razorpay-Event-Id');
        $eventId = is_string($header) && $header !== '' ? $header : 'sha256:'.hash('sha256', $request->getContent());

        try {
            $event = WebhookEvent::query()->firstOrCreate(
                ['event_id' => $eventId],
                ['event' => $payload['event'], 'payload' => $payload],
            );
        } catch (UniqueConstraintViolationException) {
            // A concurrent delivery of the same event won the insert.
            return response()->json(['status' => 'duplicate']);
        }

        if (! $event->wasRecentlyCreated && $event->processed_at !== null && config('razorpay-billing.webhook_tolerance_events') === true) {
            return response()->json(['status' => 'duplicate']);
        }

        $job = new ProcessWebhookEvent($event->id);

        if (config('razorpay-billing.webhook_queue.enabled') === false) {
            dispatch_sync($job);

            return response()->json(['status' => 'processed']);
        }

        $connection = config('razorpay-billing.webhook_queue.connection');
        $queue = config('razorpay-billing.webhook_queue.queue');

        dispatch($job->onConnection(is_string($connection) ? $connection : null)->onQueue(is_string($queue) ? $queue : null));

        return response()->json(['status' => 'queued']);
    }
}
