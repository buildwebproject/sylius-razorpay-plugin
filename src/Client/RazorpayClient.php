<?php

declare(strict_types=1);

namespace BuildWebProject\SyliusRazorpayPlugin\Client;

use BuildWebProject\SyliusRazorpayPlugin\Gateway\RazorpayConfig;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimal Razorpay REST client. It uses its own HTTP client (no global retry middleware), so a POST that timed out is
 * never sent twice; callers reconcile by receipt instead.
 */
final class RazorpayClient
{
    private readonly HttpClientInterface $http;

    public function __construct(
        private readonly string $baseUrl,
        private readonly float $timeout,
        ?HttpClientInterface $http = null,
    ) {
        $this->http = $http ?? HttpClient::create();
    }

    /** @param array<string, string> $notes @return array<string, mixed> */
    public function createOrder(RazorpayConfig $config, int $amount, string $currency, string $receipt, array $notes = []): array
    {
        return $this->request($config, 'POST', 'orders', [
            'amount' => $amount,
            'currency' => $currency,
            'receipt' => $receipt,
            'partial_payment' => false,
            'notes' => (object) $notes,
        ]);
    }

    /** @return array<string, mixed> */
    public function fetchOrder(RazorpayConfig $config, string $orderId): array
    {
        return $this->request($config, 'GET', 'orders/' . self::id($orderId, 'order'));
    }

    /** @return list<array<string, mixed>> orders created with this receipt */
    public function findOrdersByReceipt(RazorpayConfig $config, string $receipt): array
    {
        return self::items($this->request($config, 'GET', 'orders', ['receipt' => $receipt, 'count' => 10]));
    }

    /** @return list<array<string, mixed>> */
    public function fetchOrderPayments(RazorpayConfig $config, string $orderId): array
    {
        return self::items($this->request($config, 'GET', 'orders/' . self::id($orderId, 'order') . '/payments'));
    }

    /** @return array<string, mixed> */
    public function fetchPayment(RazorpayConfig $config, string $paymentId): array
    {
        return $this->request($config, 'GET', 'payments/' . self::id($paymentId, 'pay'));
    }

    /** @return array<string, mixed> */
    public function capturePayment(RazorpayConfig $config, string $paymentId, int $amount, string $currency): array
    {
        return $this->request($config, 'POST', 'payments/' . self::id($paymentId, 'pay') . '/capture', ['amount' => $amount, 'currency' => $currency]);
    }

    /** @param array<string, string> $notes @return array<string, mixed> */
    public function refund(RazorpayConfig $config, string $paymentId, int $amount, string $receipt, array $notes = []): array
    {
        return $this->request($config, 'POST', 'payments/' . self::id($paymentId, 'pay') . '/refund', [
            'amount' => $amount,
            'speed' => 'normal',
            'receipt' => $receipt,
            'notes' => (object) $notes,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function fetchPaymentRefunds(RazorpayConfig $config, string $paymentId): array
    {
        return self::items($this->request($config, 'GET', 'payments/' . self::id($paymentId, 'pay') . '/refunds', ['count' => 100]));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function request(RazorpayConfig $config, string $method, string $path, array $data = []): array
    {
        try {
            $response = $this->http->request($method, rtrim($this->baseUrl, '/') . '/' . $path, [
                'auth_basic' => [$config->keyId, $config->keySecret],
                'headers' => ['Accept' => 'application/json'],
                'timeout' => $this->timeout,
                'max_duration' => $this->timeout + 5,
                'max_redirects' => 0,
                $method === 'GET' ? 'query' : 'json' => $data,
            ]);

            $body = $response->toArray();
        } catch (HttpExceptionInterface $exception) {
            $error = [];
            try {
                $error = $exception->getResponse()->toArray(false)['error'] ?? [];
            } catch (\Throwable) {
            }
            throw new RazorpayException(
                sprintf('Razorpay %s %s failed: %s', $method, preg_replace('/\/(pay|order|rfnd)_\w+/', '/$1_…', $path), \is_array($error) ? (string) ($error['description'] ?? 'HTTP ' . $exception->getResponse()->getStatusCode()) : 'error'),
                $exception->getResponse()->getStatusCode(),
                $exception,
                \is_array($error) ? (string) ($error['code'] ?? '') : '',
            );
        } catch (\Throwable $exception) {
            throw new RazorpayException(sprintf('Razorpay %s request could not be completed (network or timeout).', $method), 0, $exception, 'NETWORK');
        }

        return $body;
    }

    private static function id(string $id, string $prefix): string
    {
        if (preg_match('/^' . $prefix . '_[A-Za-z0-9]{6,40}$/D', $id) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid Razorpay %s id.', $prefix));
        }

        return $id;
    }

    /** @param array<string, mixed> $collection @return list<array<string, mixed>> */
    private static function items(array $collection): array
    {
        $items = $collection['items'] ?? [];

        return \is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }
}
