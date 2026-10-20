<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Events;

use Illuminate\Database\Eloquent\Model;
use Shasha1589\RazorpayBilling\Models\Payment;
use Shasha1589\RazorpayBilling\Models\Subscription;

/**
 * A recurring charge succeeded (subscription.charged). The payment is $payment.
 */
class SubscriptionCharged extends SubscriptionEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(Model $billable, Subscription $subscription, public readonly Payment $payment, array $payload)
    {
        parent::__construct($billable, $subscription, $payload);
    }
}
