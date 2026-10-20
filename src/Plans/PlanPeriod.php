<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Plans;

/**
 * @see https://razorpay.com/docs/api/payments/subscriptions/create-plan
 */
enum PlanPeriod: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    /** Razorpay requires an interval of at least 7 for daily plans. */
    public function minimumInterval(): int
    {
        return $this === self::Daily ? 7 : 1;
    }
}
