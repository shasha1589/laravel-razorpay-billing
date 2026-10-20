<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Webhooks\Handlers;

/**
 * subscription.resumed: status → active; fires SubscriptionResumed.
 *
 * @see https://razorpay.com/docs/webhooks/subscriptions
 */
class SubscriptionResumedHandler extends SubscriptionStateHandler {}
