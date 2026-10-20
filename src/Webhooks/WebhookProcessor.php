<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Webhooks;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Shasha1589\RazorpayBilling\Events\WebhookReceived;
use Shasha1589\RazorpayBilling\Models\WebhookEvent;
use Throwable;

/**
 * Runs the handler for a stored webhook event and records the outcome.
 *
 * @see https://razorpay.com/docs/webhooks/subscriptions
 * @see https://razorpay.com/docs/webhooks/payments
 */
class WebhookProcessor
{
    /** @var array<string, class-string<WebhookHandler>> */
    public const HANDLERS = [
        'subscription.authenticated' => Handlers\SubscriptionAuthenticatedHandler::class,
        'subscription.activated' => Handlers\SubscriptionActivatedHandler::class,
        'subscription.charged' => Handlers\SubscriptionChargedHandler::class,
        'subscription.pending' => Handlers\SubscriptionPendingHandler::class,
        'subscription.halted' => Handlers\SubscriptionHaltedHandler::class,
        'subscription.paused' => Handlers\SubscriptionPausedHandler::class,
        'subscription.resumed' => Handlers\SubscriptionResumedHandler::class,
        'subscription.cancelled' => Handlers\SubscriptionCancelledHandler::class,
        'subscription.completed' => Handlers\SubscriptionCompletedHandler::class,
        'subscription.updated' => Handlers\SubscriptionUpdatedHandler::class,
        'payment.captured' => Handlers\PaymentCapturedHandler::class,
        'payment.failed' => Handlers\PaymentFailedHandler::class,
        'refund.processed' => Handlers\RefundProcessedHandler::class,
        'invoice.paid' => Handlers\InvoicePaidHandler::class,
        'invoice.expired' => Handlers\InvoiceExpiredHandler::class,
        'token.cancelled' => Handlers\TokenCancelledHandler::class,
        'token.paused' => Handlers\TokenPausedHandler::class,
    ];

    public function __construct(private readonly Container $container) {}

    public function process(WebhookEvent $event): void
    {
        $handler = self::HANDLERS[$event->event] ?? null;

        try {
            DB::transaction(function () use ($event, $handler) {
                if ($handler !== null) {
                    /** @var WebhookHandler $instance */
                    $instance = $this->container->make($handler);
                    $instance->handle($event);
                }

                $event->forceFill(['processed_at' => Carbon::now(), 'error' => null])->save();
            });
        } catch (Throwable $e) {
            $event->forceFill(['error' => Str::limit($e::class.': '.$e->getMessage(), 2000)])->save();

            throw $e;
        }

        WebhookReceived::dispatch($event, $event->payload);
    }
}
