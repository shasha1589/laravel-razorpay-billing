<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Exceptions;

use LogicException;

class SubscriptionCreationFailed extends LogicException
{
    public static function planNotSynced(string $key): self
    {
        return new self("Plan [{$key}] has no Razorpay plan ID. Run `php artisan razorpay:sync-plans`.");
    }

    public static function totalCountMissing(): self
    {
        return new self('Razorpay requires a number of billing cycles. Call totalCount() or set `total_count` on the plan.');
    }

    public static function upiLimitExceeded(int $amount, int $limit): self
    {
        return new self("UPI AutoPay is limited to {$limit} paise per charge, but this subscription charges {$amount} paise. Allow card or emandate instead.");
    }

    /**
     * @param  list<string>  $methods
     */
    public static function methodsNotAllowed(array $methods): self
    {
        return new self('Payment methods not allowed for this plan: '.implode(', ', $methods).'.');
    }

    public static function tooManyNotes(int $max): self
    {
        return new self("A subscription can have at most {$max} notes.");
    }
}
