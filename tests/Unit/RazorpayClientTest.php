<?php

declare(strict_types=1);

namespace Tests\BuildWebProject\SyliusRazorpayPlugin\Unit;

use BuildWebProject\SyliusRazorpayPlugin\Client\RazorpayClient;
use BuildWebProject\SyliusRazorpayPlugin\Client\RazorpayException;
use BuildWebProject\SyliusRazorpayPlugin\Gateway\RazorpayConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RazorpayClientTest extends TestCase
{
    private RazorpayConfig $config;

    protected function setUp(): void
    {
        $this->config = new RazorpayConfig('test', 'rzp_test_ABCDEF123456', 'key-secret-0123456789', 'webhook-secret-0123456789', '', ['card'], '', '#3399cc');
    }

    public function testCreateOrderSendsAmountReceiptAndBasicAuth(): void
    {
        $sent = null;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = compact('method', 'url', 'options');

            return new MockResponse('{"id":"order_ABC123456","amount":49900,"currency":"INR","receipt":"sy_x"}');
        });

        $order = (new RazorpayClient('https://api.example.test/v1/', 5.0, $http))->createOrder($this->config, 49900, 'INR', 'sy_x', ['sylius_order' => '000001']);

        self::assertSame('order_ABC123456', $order['id']);
        self::assertSame('POST', $sent['method']);
        self::assertSame('https://api.example.test/v1/orders', $sent['url']);
        self::assertContains('Authorization: Basic ' . base64_encode('rzp_test_ABCDEF123456:key-secret-0123456789'), $sent['options']['headers']);
        $body = json_decode($sent['options']['body'], true);
        self::assertSame(49900, $body['amount']);
        self::assertSame('sy_x', $body['receipt']);
        self::assertFalse($body['partial_payment']);
    }

    public function testApiErrorIsCertainAndCarriesRazorpayCode(): void
    {
        $http = new MockHttpClient(new MockResponse('{"error":{"code":"BAD_REQUEST_ERROR","description":"The amount must be atleast INR 1.00"}}', ['http_code' => 400]));

        try {
            (new RazorpayClient('https://api.example.test/v1/', 5.0, $http))->createOrder($this->config, 10, 'INR', 'sy_x');
            self::fail('Expected an exception.');
        } catch (RazorpayException $exception) {
            self::assertFalse($exception->isUncertain());
            self::assertSame('BAD_REQUEST_ERROR', $exception->getErrorCode());
            self::assertStringContainsString('atleast INR 1.00', $exception->getMessage());
        }
    }

    public function testServerErrorAndTimeoutAreUncertain(): void
    {
        foreach ([new MockResponse('', ['http_code' => 502]), new MockResponse('', ['error' => 'timeout'])] as $response) {
            try {
                (new RazorpayClient('https://api.example.test/v1/', 5.0, new MockHttpClient($response)))->createOrder($this->config, 49900, 'INR', 'sy_x');
                self::fail('Expected an exception.');
            } catch (RazorpayException $exception) {
                self::assertTrue($exception->isUncertain());
            }
        }
    }

    public function testIdsAreValidatedBeforeBuildingUrls(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RazorpayClient('https://api.example.test/v1/', 5.0, new MockHttpClient()))->fetchPayment($this->config, '../orders');
    }

    public function testRefundUsesReceiptAsIdempotencyKey(): void
    {
        $sent = null;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = compact('url', 'options');

            return new MockResponse('{"id":"rfnd_ABC123456","amount":1000,"status":"processed"}');
        });

        (new RazorpayClient('https://api.example.test/v1/', 5.0, $http))->refund($this->config, 'pay_ABC123456', 1000, 'sylius_refund_7');

        self::assertSame('https://api.example.test/v1/payments/pay_ABC123456/refund', $sent['url']);
        self::assertSame('sylius_refund_7', json_decode($sent['options']['body'], true)['receipt']);
    }
}
