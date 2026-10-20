<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Shasha1589\RazorpayBilling\Models\Subscription;

abstract class SubscriptionEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $payload  Raw webhook body, or the Razorpay entity for API syncs.
     */
    public function __construct(
        public readonly Model $billable,
        public readonly Subscription $subscription,
        public readonly array $payload,
    ) {}
}
