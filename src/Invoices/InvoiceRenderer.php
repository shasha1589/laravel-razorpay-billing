<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Invoices;

use Shasha1589\RazorpayBilling\Models\Invoice;

/**
 * Renders a GST invoice (e.g. to PDF). Set the class in
 * `razorpay-billing.invoices.renderer`; the package ships no PDF library.
 */
interface InvoiceRenderer
{
    /**
     * Renders and stores the invoice, returning the stored file path (saved
     * as the invoice's pdf_path), or null if nothing was stored.
     */
    public function render(Invoice $invoice): ?string;
}
