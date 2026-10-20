<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Webhooks\Handlers;

/**
 * subscription.updated: syncs plan and quantity; fires SubscriptionUpdated.
 *
 * @see https://razorpay.com/docs/webhooks/subscriptions
 */
class SubscriptionUpdatedHandler extends SubscriptionStateHandler {}
