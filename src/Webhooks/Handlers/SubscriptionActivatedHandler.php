<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Webhooks\Handlers;

/**
 * subscription.activated: status → active; fires SubscriptionActivated.
 *
 * @see https://razorpay.com/docs/webhooks/subscriptions
 */
class SubscriptionActivatedHandler extends SubscriptionStateHandler {}
