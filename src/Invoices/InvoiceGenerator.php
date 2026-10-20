<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Invoices;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Shasha1589\RazorpayBilling\Enums\PaymentStatus;
use Shasha1589\RazorpayBilling\Events\GstInvoiceGenerated;
use Shasha1589\RazorpayBilling\Models\Invoice;
use Shasha1589\RazorpayBilling\Models\Payment;

/**
 * Creates one GST invoice per captured payment when the module is enabled.
 * Safe to call repeatedly: an existing invoice is returned unchanged.
 */
class InvoiceGenerator
{
    public function __construct(
        private readonly Config $config,
        private readonly Container $container,
    ) {}

    public function enabled(): bool
    {
        return $this->config->get('razorpay-billing.invoices.enabled') === true;
    }

    public function forPayment(Payment $payment): ?Invoice
    {
        if (! $this->enabled() || ! in_array($payment->status, [PaymentStatus::Captured, PaymentStatus::Refunded], true)) {
            return null;
        }

        $existing = Invoice::query()->where('razorpay_payment_id', $payment->razorpay_payment_id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $billable = $payment->billable;
        $buyerState = $billable->getAttribute('billing_state_code');
        $buyerGstin = $billable->getAttribute('billing_gstin');
        $breakdown = GstCalculator::fromConfig($this->config)->split($payment->amount, is_string($buyerState) ? $buyerState : null);

        $invoice = DB::transaction(function () use ($payment, $billable, $breakdown, $buyerGstin) {
            $issuedAt = Carbon::now();
            $number = $this->numbers()->next($issuedAt);

            $invoice = new Invoice([
                'razorpay_payment_id' => $payment->razorpay_payment_id,
                'number' => $number['number'],
                'financial_year' => $number['financial_year'],
                'sequence' => $number['sequence'],
                'subtotal' => $breakdown->subtotal,
                'cgst' => $breakdown->cgst,
                'sgst' => $breakdown->sgst,
                'igst' => $breakdown->igst,
                'total' => $breakdown->total,
                'place_of_supply' => $breakdown->placeOfSupply,
                'buyer_gstin' => is_string($buyerGstin) && $buyerGstin !== '' ? $buyerGstin : null,
                'issued_at' => $issuedAt,
            ]);
            $invoice->billable()->associate($billable);
            $invoice->save();

            return $invoice;
        });

        GstInvoiceGenerated::dispatch($billable, $invoice, $payment);

        $this->render($invoice);

        return $invoice;
    }

    /** Runs the configured renderer for an invoice without a stored PDF. */
    public function render(Invoice $invoice): void
    {
        $renderer = $this->config->get('razorpay-billing.invoices.renderer');

        if (! is_string($renderer) || $renderer === '' || $invoice->pdf_path !== null) {
            return;
        }

        $instance = $this->container->make($renderer);

        if (! $instance instanceof InvoiceRenderer) {
            throw new InvalidInvoiceConfiguration("{$renderer} must implement ".InvoiceRenderer::class.'.');
        }

        $path = $instance->render($invoice);

        if ($path !== null) {
            $invoice->forceFill(['pdf_path' => $path])->save();
        }
    }

    private function numbers(): InvoiceNumberGenerator
    {
        $prefix = $this->config->get('razorpay-billing.invoices.number_prefix');

        return new InvoiceNumberGenerator(is_string($prefix) && $prefix !== '' ? $prefix : 'INV');
    }
}
