<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Webhooks\Handlers;

/**
 * payment.captured: upserts the payment; fires PaymentCaptured.
 *
 * @see https://razorpay.com/docs/webhooks/payments
 */
class PaymentCapturedHandler extends PaymentHandler {}
