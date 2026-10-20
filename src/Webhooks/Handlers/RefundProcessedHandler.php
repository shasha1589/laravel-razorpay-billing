<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Webhooks\Handlers;

/**
 * refund.processed: updates the payment's refunded amount. PaymentSync fires
 * RefundProcessed when it increased.
 *
 * @see https://razorpay.com/docs/webhooks/refunds
 */
class RefundProcessedHandler extends PaymentHandler {}
