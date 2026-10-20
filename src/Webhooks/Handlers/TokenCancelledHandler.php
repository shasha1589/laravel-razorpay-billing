<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Webhooks\Handlers;

use Shasha1589\RazorpayBilling\Events\MandateCancelled;

/**
 * token.cancelled: sets mandate_status; fires MandateCancelled.
 *
 * @see https://razorpay.com/docs/api/payments/recurring-payments/webhooks
 */
class TokenCancelledHandler extends TokenHandler
{
    protected function event(): string
    {
        return MandateCancelled::class;
    }

    protected function defaultStatus(): string
    {
        return 'cancelled';
    }
}
