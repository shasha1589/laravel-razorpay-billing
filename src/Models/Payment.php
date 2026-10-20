<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Shasha1589\RazorpayBilling\Client\RazorpayClient;
use Shasha1589\RazorpayBilling\Enums\PaymentStatus;
use Shasha1589\RazorpayBilling\Exceptions\RefundFailure;
use Shasha1589\RazorpayBilling\Sync\PaymentSync;

/**
 * @property int $id
 * @property string $billable_type
 * @property int|string $billable_id
 * @property string $razorpay_payment_id
 * @property string|null $razorpay_order_id
 * @property string|null $razorpay_subscription_id
 * @property string|null $razorpay_invoice_id
 * @property int $amount
 * @property string $currency
 * @property PaymentStatus $status
 * @property string|null $method
 * @property int $amount_refunded
 * @property Carbon|null $captured_at
 * @property array<string, string>|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model $billable
 */
class Payment extends Model
{
    protected $table = 'razorpay_payments';

    protected $guarded = [];

    protected $attributes = [
        'amount_refunded' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'integer',
            'amount_refunded' => 'integer',
            'captured_at' => 'datetime',
            'notes' => 'array',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Refunds the payment, fully when no amount is given. A processed refund
     * is applied at once (firing RefundProcessed); a pending one is applied
     * by the refund.processed webhook.
     *
     * @see https://razorpay.com/docs/api/refunds/create-normal
     *
     * @param  array<string, string>  $notes
     * @return array<string, mixed> The Razorpay refund entity.
     */
    public function refund(?int $amount = null, array $notes = []): array
    {
        if ($this->status !== PaymentStatus::Captured) {
            throw RefundFailure::notCaptured($this);
        }

        if ($amount !== null && ($amount < 1 || $amount > $this->amount - $this->amount_refunded)) {
            throw RefundFailure::invalidAmount($this, $amount);
        }

        $client = app(RazorpayClient::class);

        $refund = $client->payments()->refund($this->razorpay_payment_id, array_filter([
            'amount' => $amount,
            'notes' => $notes === [] ? null : $notes,
        ], fn ($value) => $value !== null));

        if (($refund['status'] ?? null) === 'processed') {
            $payment = $client->payments()->fetch($this->razorpay_payment_id);
            app(PaymentSync::class)->apply($payment, null, ['refund' => $refund, 'payment' => $payment]);
            $this->refresh();
        }

        return $refund;
    }

    public function refunded(): bool
    {
        return $this->amount_refunded > 0 && $this->amount_refunded >= $this->amount;
    }

    public function partiallyRefunded(): bool
    {
        return $this->amount_refunded > 0 && $this->amount_refunded < $this->amount;
    }
}
