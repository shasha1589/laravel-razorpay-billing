<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Enums;

/**
 * @see https://razorpay.com/docs/payments/payments/#payment-life-cycle
 */
enum PaymentStatus: string
{
    case Created = 'created';
    case Authorized = 'authorized';
    case Captured = 'captured';
    case Refunded = 'refunded';
    case Failed = 'failed';

    /**
     * Position in the payment life cycle. A webhook may only move a payment
     * forward, so a late `payment.failed` can't undo a capture.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Created => 0,
            self::Authorized, self::Failed => 1,
            self::Captured => 2,
            self::Refunded => 3,
        };
    }
}
