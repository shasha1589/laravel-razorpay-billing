<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Webhooks\Handlers;

/**
 * payment.failed: upserts the payment; fires PaymentFailed.
 *
 * @see https://razorpay.com/docs/webhooks/payments
 */
class PaymentFailedHandler extends PaymentHandler {}
