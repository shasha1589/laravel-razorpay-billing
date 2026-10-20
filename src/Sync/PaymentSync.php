<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Sync;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Shasha1589\RazorpayBilling\Client\Exceptions\NotFoundException;
use Shasha1589\RazorpayBilling\Client\RazorpayClient;
use Shasha1589\RazorpayBilling\Enums\PaymentStatus;
use Shasha1589\RazorpayBilling\Events\PaymentCaptured;
use Shasha1589\RazorpayBilling\Events\PaymentFailed;
use Shasha1589\RazorpayBilling\Events\RefundProcessed;
use Shasha1589\RazorpayBilling\Invoices\InvoiceGenerator;
use Shasha1589\RazorpayBilling\Models\Payment;
use Shasha1589\RazorpayBilling\Models\Subscription;

/**
 * Upserts local payments from Razorpay payment entities. Only IDs, status,
 * amounts and method are stored, never card, UPI or bank details.
 */
final class PaymentSync
{
    public function __construct(
        private readonly BillableResolver $billables,
        private readonly InvoiceGenerator $invoices,
    ) {}

    /**
     * Returns null when the payment can't be linked to a billable: it isn't
     * for a known subscription, and its order wasn't created by charge().
     *
     * @see https://razorpay.com/docs/webhooks/payments
     *
     * @param  array<string, mixed>  $entity
     * @param  array<string, mixed>  $payload  Passed to fired events.
     */
    public function apply(array $entity, ?Subscription $subscription, array $payload): ?Payment
    {
        $id = $entity['id'] ?? null;

        if (! is_string($id)) {
            return null;
        }

        $payment = Payment::query()->where('razorpay_payment_id', $id)->first();

        if ($payment === null) {
            $billable = $subscription !== null ? $subscription->billable : $this->billableFromOrder($entity['order_id'] ?? null);

            if ($billable === null) {
                return null;
            }

            $payment = new Payment(['razorpay_payment_id' => $id]);
            $payment->billable()->associate($billable);
        }

        $oldStatus = $payment->exists ? $payment->status : null;
        $oldRefunded = $payment->exists ? $payment->amount_refunded : 0;
        $this->fill($payment, $entity, $subscription);
        $payment->save();

        if ($payment->amount_refunded > $oldRefunded) {
            RefundProcessed::dispatch($payment->billable, $payment, $payload);
        }

        if ($subscription !== null && $subscription->razorpay_token_id === null && is_string($entity['token_id'] ?? null)) {
            $subscription->razorpay_token_id = $entity['token_id'];
            $subscription->save();
        }

        $this->invoices->forPayment($payment);

        if ($payment->status !== $oldStatus) {
            match ($payment->status) {
                PaymentStatus::Captured => PaymentCaptured::dispatch($payment->billable, $payment, $payload),
                PaymentStatus::Failed => PaymentFailed::dispatch($payment->billable, $payment, $payload),
                default => null,
            };
        }

        return $payment;
    }

    /**
     * The billable from the notes of the order charge() created. Payment notes
     * are not trusted: they come from Checkout options in the browser.
     *
     * @see https://razorpay.com/docs/api/orders/fetch-with-id
     */
    private function billableFromOrder(mixed $orderId): ?Model
    {
        if (! is_string($orderId) || $orderId === '') {
            return null;
        }

        try {
            $order = app(RazorpayClient::class)->orders()->fetch($orderId);
        } catch (NotFoundException) {
            return null;
        }

        return is_array($order['notes'] ?? null) ? $this->billables->fromNotes($order['notes']) : null;
    }

    /**
     * @param  array<string, mixed>  $entity
     */
    private function fill(Payment $payment, array $entity, ?Subscription $subscription): void
    {
        $string = fn (string $key): ?string => is_string($entity[$key] ?? null) ? $entity[$key] : null;

        $payment->razorpay_order_id = $string('order_id') ?? $payment->razorpay_order_id;
        $payment->razorpay_invoice_id = $string('invoice_id') ?? $payment->razorpay_invoice_id;
        $payment->razorpay_subscription_id = $subscription->razorpay_id ?? $payment->razorpay_subscription_id;
        $payment->currency = $string('currency') ?? $payment->currency ?? 'INR';
        $payment->method = $string('method') ?? $payment->method;

        if (is_int($entity['amount'] ?? null)) {
            $payment->amount = $entity['amount'];
        }

        if (is_int($entity['amount_refunded'] ?? null)) {
            $payment->amount_refunded = max($payment->amount_refunded, $entity['amount_refunded']);
        }

        $status = is_string($entity['status'] ?? null) ? PaymentStatus::tryFrom($entity['status']) : null;

        if ($status !== null && (! $payment->exists || $status->rank() >= $payment->status->rank())) {
            $payment->status = $status;
        }

        if ($payment->captured_at === null && in_array($payment->status, [PaymentStatus::Captured, PaymentStatus::Refunded], true)) {
            $payment->captured_at = Carbon::now();
        }

        $notes = $entity['notes'] ?? null;

        if (is_array($notes) && $notes !== []) {
            /** @var array<string, string> $notes */
            $payment->notes = $notes;
        }
    }
}
