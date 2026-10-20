<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Webhooks\Handlers;

/**
 * subscription.pending: status → pending; fires SubscriptionPastDue.
 *
 * @see https://razorpay.com/docs/webhooks/subscriptions
 */
class SubscriptionPendingHandler extends SubscriptionStateHandler {}
