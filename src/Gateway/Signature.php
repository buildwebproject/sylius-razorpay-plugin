<?php

declare(strict_types=1);

namespace BuildWebProject\SyliusRazorpayPlugin\Gateway;

/** Razorpay HMAC-SHA256 signatures, compared in constant time. */
final class Signature
{
    public static function isValid(string $payload, string $signature, #[\SensitiveParameter] string $secret): bool
    {
        if ($secret === '' || preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }

    /** Checkout handler signature: HMAC(order_id|payment_id, key secret). */
    public static function isValidPayment(string $orderId, string $paymentId, string $signature, RazorpayConfig $config): bool
    {
        return self::isValid($orderId . '|' . $paymentId, $signature, $config->keySecret);
    }

    /** Webhook signature over the raw body; the previous secret keeps working during a rotation. */
    public static function isValidWebhook(string $rawBody, string $signature, RazorpayConfig $config): bool
    {
        return self::isValid($rawBody, $signature, $config->webhookSecret)
            || self::isValid($rawBody, $signature, $config->previousWebhookSecret);
    }
}
