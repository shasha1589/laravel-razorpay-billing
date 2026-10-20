<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Events;

/**
 * The customer cancelled the mandate, e.g. from their UPI app (token.cancelled).
 */
class MandateCancelled extends SubscriptionEvent {}
