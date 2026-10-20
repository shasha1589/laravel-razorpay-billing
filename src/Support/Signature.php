<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Support;

/**
 * Constant-time verification of Razorpay HMAC-SHA256 signatures.
 */
final class Signature
{
    /**
     * Checkout signature for a subscription authorisation payment:
     * hmac_sha256(razorpay_payment_id + "|" + subscription_id, key_secret).
     *
     * @see https://razorpay.com/docs/payments/subscriptions/integration-guide
     */
    public static function verifySubscriptionPayment(string $paymentId, string $subscriptionId, string $signature, #[\SensitiveParameter] string $secret): bool
    {
        return self::verify($paymentId.'|'.$subscriptionId, $signature, $secret);
    }

    /**
     * Checkout signature for an order payment:
     * hmac_sha256(order_id + "|" + razorpay_payment_id, key_secret).
     *
     * @see https://razorpay.com/docs/payments/payment-gateway/web-integration/standard/integration-steps
     */
    public static function verifyOrderPayment(string $orderId, string $paymentId, string $signature, #[\SensitiveParameter] string $secret): bool
    {
        return self::verify($orderId.'|'.$paymentId, $signature, $secret);
    }

    /**
     * Webhook signature: hmac_sha256(raw request body, webhook_secret), sent
     * in the X-Razorpay-Signature header.
     *
     * @see https://razorpay.com/docs/webhooks/validate-test
     */
    public static function verifyWebhook(string $rawBody, string $signature, #[\SensitiveParameter] string $secret): bool
    {
        return self::verify($rawBody, $signature, $secret);
    }

    private static function verify(string $payload, string $signature, string $secret): bool
    {
        if ($secret === '' || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }
}
