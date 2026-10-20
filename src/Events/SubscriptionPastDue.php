<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Events;

/**
 * A charge failed and Razorpay is retrying (subscription.pending).
 */
class SubscriptionPastDue extends SubscriptionEvent {}
