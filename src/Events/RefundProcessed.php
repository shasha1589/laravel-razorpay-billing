<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Events;

/**
 * A refund was processed; $payment->amount_refunded is up to date (refund.processed).
 */
class RefundProcessed extends PaymentEvent {}
