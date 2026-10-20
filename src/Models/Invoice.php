<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A local GST invoice for a captured payment. Amounts are paise.
 *
 * @property int $id
 * @property string $billable_type
 * @property int|string $billable_id
 * @property string $razorpay_payment_id
 * @property string $number
 * @property string $financial_year
 * @property int $sequence
 * @property int $subtotal
 * @property int $cgst
 * @property int $sgst
 * @property int $igst
 * @property int $total
 * @property string $place_of_supply
 * @property string|null $buyer_gstin
 * @property Carbon $issued_at
 * @property string|null $pdf_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model $billable
 * @property-read Payment|null $payment
 */
class Invoice extends Model
{
    protected $table = 'razorpay_invoices';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'subtotal' => 'integer',
            'cgst' => 'integer',
            'sgst' => 'integer',
            'igst' => 'integer',
            'total' => 'integer',
            'issued_at' => 'datetime',
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
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'razorpay_payment_id', 'razorpay_payment_id');
    }

    public function tax(): int
    {
        return $this->cgst + $this->sgst + $this->igst;
    }

    /** Zero-rated export of services (place of supply is the export state code). */
    public function isExport(): bool
    {
        return $this->tax() === 0 && $this->place_of_supply === config('razorpay-billing.invoices.export_state_code', '96');
    }

    public function isIntraState(): bool
    {
        return $this->cgst + $this->sgst > 0;
    }
}
