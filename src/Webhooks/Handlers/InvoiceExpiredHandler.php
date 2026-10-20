<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Webhooks\Handlers;

use Shasha1589\RazorpayBilling\Events\InvoiceExpired;

/**
 * invoice.expired: fires InvoiceExpired.
 *
 * @see https://razorpay.com/docs/webhooks/invoices
 */
class InvoiceExpiredHandler extends InvoiceHandler
{
    protected function event(): string
    {
        return InvoiceExpired::class;
    }
}
