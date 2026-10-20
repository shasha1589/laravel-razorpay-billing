<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Exceptions;

use LogicException;
use Shasha1589\RazorpayBilling\Models\Subscription;

class SubscriptionUpdateFailure extends LogicException
{
    public static function notUpdatable(Subscription $subscription): self
    {
        return new self("Subscription {$subscription->razorpay_id} is {$subscription->status->value}; Razorpay only updates authenticated or active subscriptions.");
    }

    public static function paymentMethodNotUpdatable(Subscription $subscription): self
    {
        return new self("Razorpay can't update {$subscription->payment_method} subscriptions. Cancel {$subscription->razorpay_id} and create a new subscription instead.");
    }

    public static function notPausable(Subscription $subscription): self
    {
        return new self("Only active subscriptions can be paused; {$subscription->razorpay_id} is {$subscription->status->value}.");
    }

    public static function notResumable(Subscription $subscription): self
    {
        return new self("Only paused subscriptions can be resumed; {$subscription->razorpay_id} is {$subscription->status->value}.");
    }

    public static function ended(Subscription $subscription): self
    {
        return new self("Subscription {$subscription->razorpay_id} has ended.");
    }

    public static function invalidSchedule(string $when): self
    {
        return new self("Changes can be scheduled `now` or at `cycle_end`, not `{$when}`.");
    }
}
