<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Shasha1589\RazorpayBilling\Models\Subscription;

/**
 * A Razorpay invoice changed. The billable and subscription are null when the
 * invoice can't be linked to a local subscription.
 */
abstract class InvoiceEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $invoice  The Razorpay invoice entity.
     * @param  array<string, mixed>  $payload  Raw webhook body.
     */
    public function __construct(
        public readonly ?Model $billable,
        public readonly ?Subscription $subscription,
        public readonly array $invoice,
        public readonly array $payload,
    ) {}
}
