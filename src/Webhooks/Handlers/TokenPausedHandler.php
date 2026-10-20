<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Webhooks\Handlers;

use Shasha1589\RazorpayBilling\Events\MandatePaused;

/**
 * token.paused: sets mandate_status; fires MandatePaused.
 *
 * @see https://razorpay.com/docs/api/payments/recurring-payments/webhooks
 */
class TokenPausedHandler extends TokenHandler
{
    protected function event(): string
    {
        return MandatePaused::class;
    }

    protected function defaultStatus(): string
    {
        return 'paused';
    }
}
