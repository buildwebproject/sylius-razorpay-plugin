<?php

declare(strict_types=1);

namespace Tests\BuildWebProject\SyliusRazorpayPlugin\Unit;

use BuildWebProject\SyliusRazorpayPlugin\Gateway\RazorpayConfig;
use BuildWebProject\SyliusRazorpayPlugin\Gateway\Signature;
use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase
{
    private function config(string $webhookSecret = 'webhook-secret-0123456789', string $previous = ''): RazorpayConfig
    {
        return new RazorpayConfig('test', 'rzp_test_ABCDEF123456', 'key-secret-0123456789', $webhookSecret, $previous, ['card', 'upi'], 'Shop', '#3399cc');
    }

    public function testCheckoutSignatureIsHmacOfOrderAndPayment(): void
    {
        $signature = hash_hmac('sha256', 'order_ABC123456|pay_XYZ987654', 'key-secret-0123456789');

        self::assertTrue(Signature::isValidPayment('order_ABC123456', 'pay_XYZ987654', $signature, $this->config()));
        self::assertFalse(Signature::isValidPayment('order_ABC123456', 'pay_OTHER00000', $signature, $this->config()));
        self::assertFalse(Signature::isValidPayment('order_ABC123456', 'pay_XYZ987654', strtoupper($signature), $this->config()));
        self::assertFalse(Signature::isValidPayment('order_ABC123456', 'pay_XYZ987654', '', $this->config()));
    }

    public function testWebhookSignatureAcceptsCurrentAndPreviousSecret(): void
    {
        $body = '{"event":"payment.captured"}';
        $old = hash_hmac('sha256', $body, 'old-webhook-secret-0123456789');

        self::assertTrue(Signature::isValidWebhook($body, hash_hmac('sha256', $body, 'webhook-secret-0123456789'), $this->config()));
        self::assertFalse(Signature::isValidWebhook($body, $old, $this->config()));
        self::assertTrue(Signature::isValidWebhook($body, $old, $this->config(previous: 'old-webhook-secret-0123456789')));
        self::assertFalse(Signature::isValidWebhook($body . ' ', hash_hmac('sha256', $body, 'webhook-secret-0123456789'), $this->config()));
    }

    public function testEmptySecretNeverMatches(): void
    {
        self::assertFalse(Signature::isValid('x', hash_hmac('sha256', 'x', ''), ''));
    }
}
