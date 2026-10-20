<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Exceptions;

use LogicException;
use Shasha1589\RazorpayBilling\Models\Payment;

class RefundFailure extends LogicException
{
    public static function notCaptured(Payment $payment): self
    {
        return new self("Only captured payments can be refunded; {$payment->razorpay_payment_id} is {$payment->status->value}.");
    }

    public static function invalidAmount(Payment $payment, int $amount): self
    {
        $refundable = $payment->amount - $payment->amount_refunded;

        return new self("Cannot refund {$amount} paise of {$payment->razorpay_payment_id}; {$refundable} paise is refundable.");
    }
}
