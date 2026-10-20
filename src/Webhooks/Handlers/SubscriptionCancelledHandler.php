<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Webhooks\Handlers;

/**
 * subscription.cancelled: status → cancelled, sets ends_at; fires SubscriptionCancelled.
 *
 * @see https://razorpay.com/docs/webhooks/subscriptions
 */
class SubscriptionCancelledHandler extends SubscriptionStateHandler {}
