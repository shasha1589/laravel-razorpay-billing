<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Webhooks\Handlers;

/**
 * subscription.authenticated: status → authenticated; fires SubscriptionAuthenticated.
 *
 * @see https://razorpay.com/docs/webhooks/subscriptions
 */
class SubscriptionAuthenticatedHandler extends SubscriptionStateHandler {}
