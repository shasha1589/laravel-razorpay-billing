<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Webhooks\Handlers;

/**
 * subscription.halted: status → halted; fires SubscriptionHalted.
 *
 * @see https://razorpay.com/docs/webhooks/subscriptions
 */
class SubscriptionHaltedHandler extends SubscriptionStateHandler {}
