<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Events;

/**
 * Razorpay exhausted its charge retries (subscription.halted).
 */
class SubscriptionHalted extends SubscriptionEvent {}
