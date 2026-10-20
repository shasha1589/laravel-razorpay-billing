<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Shasha1589\RazorpayBilling\Client\Exceptions\RazorpayException;
use Shasha1589\RazorpayBilling\Client\RazorpayClient;
use Shasha1589\RazorpayBilling\Models\Subscription;
use Shasha1589\RazorpayBilling\Support\Signature;
use Shasha1589\RazorpayBilling\Sync\PaymentSync;

/**
 * Verifies the Checkout handler response for instant UI feedback. Webhooks
 * remain the source of truth for subscription and payment state.
 *
 * @see https://razorpay.com/docs/payments/subscriptions/integration-guide
 * @see https://razorpay.com/docs/payments/payment-gateway/web-integration/standard/integration-steps
 */
class VerifyCheckoutController
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'razorpay_payment_id' => ['required', 'string', 'max:64'],
            'razorpay_subscription_id' => ['required_without:razorpay_order_id', 'string', 'max:64'],
            'razorpay_order_id' => ['required_without:razorpay_subscription_id', 'string', 'max:64'],
            'razorpay_signature' => ['required', 'string', 'max:128'],
        ]);

        $secret = config('razorpay-billing.key_secret');

        if (! is_string($secret)) {
            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        return $request->filled('razorpay_subscription_id')
            ? $this->verifySubscription($request, $secret)
            : $this->verifyOrder($request, $secret);
    }

    private function verifySubscription(Request $request, string $secret): JsonResponse
    {
        $subscription = Subscription::query()->where('razorpay_id', $request->string('razorpay_subscription_id')->toString())->first();

        if ($subscription === null) {
            return response()->json(['message' => 'Subscription not found.'], 404);
        }

        $paymentId = $request->string('razorpay_payment_id')->toString();

        if (! Signature::verifySubscriptionPayment($paymentId, $subscription->razorpay_id, $request->string('razorpay_signature')->toString(), $secret)) {
            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        try {
            $subscription->syncFromRazorpay();
        } catch (RazorpayException $e) {
            // The payment is verified; the webhook will bring the state up to date.
            Log::warning('Razorpay subscription sync after checkout failed.', [
                'subscription' => $subscription->razorpay_id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'verified' => true,
            'subscription' => [
                'id' => $subscription->razorpay_id,
                'type' => $subscription->type,
                'status' => $subscription->status->value,
                'valid' => $subscription->valid(),
            ],
        ]);
    }

    private function verifyOrder(Request $request, string $secret): JsonResponse
    {
        $orderId = $request->string('razorpay_order_id')->toString();
        $paymentId = $request->string('razorpay_payment_id')->toString();

        if (! Signature::verifyOrderPayment($orderId, $paymentId, $request->string('razorpay_signature')->toString(), $secret)) {
            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        $payment = null;

        try {
            $entity = app(RazorpayClient::class)->payments()->fetch($paymentId);

            // The signature proves Razorpay paired this payment with this order.
            if (($entity['order_id'] ?? null) === $orderId) {
                $payment = app(PaymentSync::class)->apply($entity, null, $entity);
            }
        } catch (RazorpayException $e) {
            Log::warning('Razorpay payment sync after checkout failed.', [
                'payment' => $paymentId,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'verified' => true,
            'payment' => $payment === null ? null : [
                'id' => $payment->razorpay_payment_id,
                'order_id' => $payment->razorpay_order_id,
                'status' => $payment->status->value,
                'amount' => $payment->amount,
            ],
        ]);
    }
}
