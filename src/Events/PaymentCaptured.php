<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Events;

/**
 * A payment was captured (payment.captured, or the payment in subscription.charged).
 */
class PaymentCaptured extends PaymentEvent {}
