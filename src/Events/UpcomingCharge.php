<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Shasha1589\RazorpayBilling\Models\Subscription;

/**
 * Fired by `razorpay:upcoming-charges` once per charge, `upcoming_charge_notice_hours`
 * before it, so the app can send its own pre-debit notification.
 */
class UpcomingCharge extends SubscriptionEvent
{
    /**
     * @param  int|null  $amount  Expected charge in paise (plan amount × quantity), when the plan is configured.
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        Model $billable,
        Subscription $subscription,
        public readonly Carbon $chargeAt,
        public readonly ?int $amount,
        array $payload = [],
    ) {
        parent::__construct($billable, $subscription, $payload);
    }
}
