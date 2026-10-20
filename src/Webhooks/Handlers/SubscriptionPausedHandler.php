<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Webhooks\Handlers;

/**
 * subscription.paused: status → paused; fires SubscriptionPaused.
 *
 * @see https://razorpay.com/docs/webhooks/subscriptions
 */
class SubscriptionPausedHandler extends SubscriptionStateHandler {}
