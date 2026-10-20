<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Webhooks\Handlers;

/**
 * subscription.completed: status → completed; fires SubscriptionCompleted.
 *
 * @see https://razorpay.com/docs/webhooks/subscriptions
 */
class SubscriptionCompletedHandler extends SubscriptionStateHandler {}
