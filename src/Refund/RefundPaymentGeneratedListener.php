<?php

declare(strict_types=1);

namespace BuildWebProject\SyliusRazorpayPlugin\Refund;

use BuildWebProject\SyliusRazorpayPlugin\Gateway\RazorpayConfig;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\RefundPlugin\Event\RefundPaymentGenerated;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Handles Sylius RefundPlugin's RefundPaymentGenerated (event bus). The refund is recorded at once and sent to Razorpay
 * when the request (or console command) finishes, after the credit memo transaction has been committed.
 */
final class RefundPaymentGeneratedListener implements EventSubscriberInterface
{
    /** @var list<int> */
    private array $toSend = [];

    /** @param RepositoryInterface<PaymentMethodInterface> $paymentMethodRepository */
    public function __construct(
        private readonly RefundProcessor $processor,
        private readonly RepositoryInterface $paymentMethodRepository,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'sendQueued',
            ConsoleEvents::TERMINATE => 'sendQueued',
        ];
    }

    public function __invoke(RefundPaymentGenerated $event): void
    {
        $method = $this->paymentMethodRepository->find($event->paymentMethodId());
        if (!$method instanceof PaymentMethodInterface || !RazorpayConfig::supports($method)) {
            return;
        }

        $refund = $this->processor->queue($event->id(), $event->paymentId(), $event->amount(), $event->currencyCode());
        if ($refund?->getId() !== null) {
            $this->toSend[] = $refund->getId();
        }
    }

    public function sendQueued(): void
    {
        $ids = $this->toSend;
        $this->toSend = [];
        foreach ($ids as $id) {
            try {
                $this->processor->send($id);
            } catch (\Throwable) {
                // Left queued/uncertain; the reconcile command sends or recovers it.
            }
        }
    }
}
