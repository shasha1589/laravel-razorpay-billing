<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Shasha1589\RazorpayBilling\Support\Signature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects webhooks whose X-Razorpay-Signature doesn't match an HMAC-SHA256 of
 * the raw body with the webhook secret.
 *
 * @see https://razorpay.com/docs/webhooks/validate-test
 */
class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('razorpay-billing.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            Log::error('Razorpay webhook rejected: RAZORPAY_WEBHOOK_SECRET is not configured.');

            abort(403, 'Invalid signature.');
        }

        $signature = $request->header('X-Razorpay-Signature');

        if (! is_string($signature) || ! Signature::verifyWebhook($request->getContent(), $signature, $secret)) {
            abort(403, 'Invalid signature.');
        }

        /** @var Response */
        return $next($request);
    }
}
