<?php

declare(strict_types=1);

namespace BuildWebProject\SyliusRazorpayPlugin\Controller;

use BuildWebProject\SyliusRazorpayPlugin\Client\RazorpayException;
use BuildWebProject\SyliusRazorpayPlugin\Gateway\InvalidConfigException;
use BuildWebProject\SyliusRazorpayPlugin\Gateway\RazorpayConfig;
use BuildWebProject\SyliusRazorpayPlugin\Gateway\Signature;
use BuildWebProject\SyliusRazorpayPlugin\Payment\PaymentProcessor;
use BuildWebProject\SyliusRazorpayPlugin\Refund\RefundProcessor;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Razorpay webhook endpoint, one URL per payment method: /payment/razorpay/webhook/{payment method code}.
 * The body is trusted only after the HMAC signature matches the method's webhook secret; the event is then used only as
 * a hint, and the real state is read back from the Razorpay API.
 */
final readonly class WebhookController
{
    private const PAYMENT_EVENTS = ['payment.authorized', 'payment.captured', 'order.paid'];

    private const REFUND_EVENTS = ['refund.created', 'refund.processed', 'refund.failed'];

    /** @param RepositoryInterface<PaymentMethodInterface> $paymentMethodRepository */
    public function __construct(
        private RepositoryInterface $paymentMethodRepository,
        private PaymentProcessor $processor,
        private ?RefundProcessor $refundProcessor,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request, string $code): Response
    {
        $method = $this->paymentMethodRepository->findOneBy(['code' => $code]);
        if (!$method instanceof PaymentMethodInterface || !RazorpayConfig::supports($method)) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        try {
            $config = RazorpayConfig::fromPaymentMethod($method);
        } catch (InvalidConfigException) {
            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $body = $request->getContent();
        if (\strlen($body) > 1_000_000 || !Signature::isValidWebhook($body, (string) $request->headers->get('X-Razorpay-Signature'), $config)) {
            $this->logger->warning('Rejected Razorpay webhook with an invalid signature for payment method {code}.', ['code' => $code]);

            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        try {
            $event = json_decode($body, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }
        $name = \is_array($event) && \is_string($event['event'] ?? null) ? $event['event'] : '';
        $payload = \is_array($event['payload'] ?? null) ? $event['payload'] : [];

        try {
            if (\in_array($name, self::PAYMENT_EVENTS, true)) {
                $orderId = $payload['payment']['entity']['order_id'] ?? $payload['order']['entity']['id'] ?? null;
                $transaction = \is_string($orderId) ? $this->processor->findTransactionByOrderId($orderId) : null;
                // Ignore orders that belong to another shop on the same Razorpay account.
                if ($transaction !== null && $transaction->getPayment()->getMethod() === $method) {
                    $this->processor->synchronize($transaction);
                }
            } elseif (\in_array($name, self::REFUND_EVENTS, true) && $this->refundProcessor !== null) {
                $refundId = $payload['refund']['entity']['id'] ?? null;
                if (\is_string($refundId)) {
                    $this->refundProcessor->refreshByRazorpayId($refundId);
                }
            }
        } catch (RazorpayException $exception) {
            // Razorpay retries webhooks that do not answer 2xx.
            $this->logger->warning('Razorpay webhook {event} could not be processed yet: {message}', ['event' => $name, 'message' => $exception->getMessage()]);

            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new Response('', Response::HTTP_NO_CONTENT, ['Cache-Control' => 'no-store']);
    }
}
