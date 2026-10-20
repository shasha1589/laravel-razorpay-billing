<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Shasha1589\RazorpayBilling\Models\Invoice;
use Shasha1589\RazorpayBilling\Models\Payment;

/**
 * A local GST invoice was generated for a captured payment.
 */
class GstInvoiceGenerated implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Model $billable,
        public readonly Invoice $invoice,
        public readonly Payment $payment,
    ) {}
}
