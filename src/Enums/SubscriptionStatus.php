<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Enums;

/**
 * @see https://razorpay.com/docs/payments/subscriptions/states
 */
enum SubscriptionStatus: string
{
    case Created = 'created';
    case Authenticated = 'authenticated';
    case Active = 'active';
    case Pending = 'pending';
    case Halted = 'halted';
    case Paused = 'paused';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case Expired = 'expired';

    /** Cancelled, completed and expired subscriptions can never be used again. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Cancelled, self::Completed, self::Expired], true);
    }
}
