<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Events;

/**
 * The customer paused the mandate, e.g. from their UPI app (token.paused).
 */
class MandatePaused extends SubscriptionEvent {}
