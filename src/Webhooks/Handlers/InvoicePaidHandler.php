<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Webhooks\Handlers;

use Shasha1589\RazorpayBilling\Events\InvoicePaid;

/**
 * invoice.paid: fires InvoicePaid.
 *
 * @see https://razorpay.com/docs/webhooks/invoices
 */
class InvoicePaidHandler extends InvoiceHandler
{
    protected function event(): string
    {
        return InvoicePaid::class;
    }
}
