<?php

declare(strict_types=1);

namespace BuildWebProject\SyliusRazorpayPlugin\Refund;

use BuildWebProject\SyliusRazorpayPlugin\Client\RazorpayClient;
use BuildWebProject\SyliusRazorpayPlugin\Client\RazorpayException;
use BuildWebProject\SyliusRazorpayPlugin\Entity\RazorpayRefund;
use BuildWebProject\SyliusRazorpayPlugin\Entity\RazorpayTransaction;
use BuildWebProject\SyliusRazorpayPlugin\Gateway\RazorpayConfig;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;

/**
 * Sends Sylius RefundPlugin refunds to Razorpay. A refund is claimed with an atomic status update before the API call
 * and carries a unique receipt, so it is never sent twice; after a timeout it is looked up by that receipt.
 */
final class RefundProcessor
{
    private const REFUND_PAYMENT_GRAPH = 'sylius_refund_refund_payment';

    private const REFUND_PAYMENT_COMPLETE = 'complete';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RazorpayClient $client,
        private readonly StateMachineInterface $stateMachine,
        private readonly LoggerInterface $logger,
        private readonly string $refundPaymentClass = 'Sylius\RefundPlugin\Entity\RefundPayment',
    ) {
    }

    /** Records the refund to send. Runs inside the credit-memo transaction, so nothing is sent from here. */
    public function queue(int $refundPaymentId, int $originalPaymentId, int $amount, string $currencyCode): ?RazorpayRefund
    {
        $transaction = $this->entityManager->getRepository(RazorpayTransaction::class)->findOneBy(['payment' => $originalPaymentId]);
        if (!$transaction instanceof RazorpayTransaction) {
            return null;
        }
        if ($transaction->getRazorpayPaymentId() === null || !\in_array($transaction->getStatus(), [RazorpayTransaction::STATUS_CAPTURED, RazorpayTransaction::STATUS_REVIEW], true)) {
            $this->logger->error('Refund payment {refund} cannot be sent to Razorpay: the original payment was never captured.', ['refund' => $refundPaymentId]);

            return null;
        }

        $refund = $this->entityManager->getRepository(RazorpayRefund::class)->findOneBy(['refundPaymentId' => $refundPaymentId]);
        if ($refund === null) {
            $refund = new RazorpayRefund($refundPaymentId, $transaction, $amount, strtoupper($currencyCode));
            $this->entityManager->persist($refund);
            $this->entityManager->flush();
        }

        return $refund;
    }

    /** Sends a queued refund. Must run after the credit-memo transaction was committed. */
    public function send(int $refundId): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException('Refunds are sent to Razorpay only outside a database transaction.');
        }

        $refund = $this->entityManager->find(RazorpayRefund::class, $refundId);
        if (!$refund instanceof RazorpayRefund) {
            return;
        }

        if ($refund->getStatus() === RazorpayRefund::STATUS_UNCERTAIN) {
            $this->recover($refund);
            if ($refund->getStatus() !== RazorpayRefund::STATUS_UNCERTAIN) {
                return;
            }
            $from = RazorpayRefund::STATUS_UNCERTAIN;
        } elseif ($refund->getStatus() === RazorpayRefund::STATUS_QUEUED) {
            $from = RazorpayRefund::STATUS_QUEUED;
        } else {
            return;
        }

        $claimed = $this->entityManager->getConnection()->executeStatement(
            'UPDATE bwp_razorpay_refund SET status = ?, updated_at = ? WHERE id = ? AND status = ?',
            [RazorpayRefund::STATUS_SENDING, (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $refundId, $from],
        );
        $this->entityManager->refresh($refund);
        if ($claimed !== 1) {
            return;
        }

        $transaction = $refund->getTransaction();
        $config = RazorpayConfig::fromPaymentMethod($transaction->getPayment()->getMethod());

        try {
            $remote = $this->client->refund($config, (string) $transaction->getRazorpayPaymentId(), $refund->getAmount(), $refund->getReceipt(), [
                'sylius_order' => (string) $transaction->getPayment()->getOrder()?->getNumber(),
                'sylius_refund_payment' => (string) $refund->getRefundPaymentId(),
            ]);
        } catch (RazorpayException $exception) {
            if ($exception->isUncertain()) {
                $refund->update(RazorpayRefund::STATUS_UNCERTAIN, null, $exception->getMessage());
            } else {
                $refund->update(RazorpayRefund::STATUS_FAILED, null, mb_substr($exception->getMessage(), 0, 255));
                $this->logger->error('Razorpay refused refund {refund}: {message}', ['refund' => $refund->getRefundPaymentId(), 'message' => $exception->getMessage()]);
            }
            $this->entityManager->flush();

            return;
        }

        $this->apply($refund, $remote);
    }

    public function refreshByRazorpayId(string $razorpayRefundId): void
    {
        $refund = $this->entityManager->getRepository(RazorpayRefund::class)->findOneBy(['razorpayRefundId' => $razorpayRefundId]);
        if ($refund instanceof RazorpayRefund) {
            $this->recover($refund);
        }
    }

    /** Re-reads unfinished refunds from Razorpay (used by the reconcile command). */
    public function processUnfinished(\DateTimeImmutable $notAfter): int
    {
        /** @var list<RazorpayRefund> $refunds */
        $refunds = $this->entityManager->createQueryBuilder()
            ->select('r')->from(RazorpayRefund::class, 'r')
            ->where('r.status IN (:statuses)')->andWhere('r.updatedAt <= :notAfter')
            ->setParameter('statuses', [RazorpayRefund::STATUS_QUEUED, RazorpayRefund::STATUS_UNCERTAIN, RazorpayRefund::STATUS_PENDING, RazorpayRefund::STATUS_SENDING])
            ->setParameter('notAfter', $notAfter)
            ->setMaxResults(200)->getQuery()->getResult();

        foreach ($refunds as $refund) {
            try {
                match ($refund->getStatus()) {
                    RazorpayRefund::STATUS_QUEUED => $this->send((int) $refund->getId()),
                    RazorpayRefund::STATUS_SENDING => $this->markUncertainThenRecover($refund),
                    default => $this->recover($refund),
                };
            } catch (RazorpayException $exception) {
                $this->logger->warning('Razorpay refund {id} check failed: {message}', ['id' => $refund->getId(), 'message' => $exception->getMessage()]);
            }
        }

        return \count($refunds);
    }

    private function markUncertainThenRecover(RazorpayRefund $refund): void
    {
        // A sending request that died mid-call: find out whether Razorpay received it.
        $refund->update(RazorpayRefund::STATUS_UNCERTAIN, null, 'Sending was interrupted.');
        $this->entityManager->flush();
        $this->recover($refund);
    }

    /** Finds the refund in Razorpay by its id or receipt and copies its state. */
    private function recover(RazorpayRefund $refund): void
    {
        $transaction = $refund->getTransaction();
        $config = RazorpayConfig::fromPaymentMethod($transaction->getPayment()->getMethod());
        foreach ($this->client->fetchPaymentRefunds($config, (string) $transaction->getRazorpayPaymentId()) as $remote) {
            if (($refund->getRazorpayRefundId() !== null && ($remote['id'] ?? null) === $refund->getRazorpayRefundId())
                || ($remote['receipt'] ?? null) === $refund->getReceipt()) {
                $this->apply($refund, $remote);

                return;
            }
        }

        if ($refund->getStatus() === RazorpayRefund::STATUS_UNCERTAIN && time() - $refund->getUpdatedAt()->getTimestamp() > 300) {
            // Razorpay has no refund with our receipt five minutes later: the call never arrived. Send it again.
            $refund->update(RazorpayRefund::STATUS_QUEUED);
            $this->entityManager->flush();
        }
    }

    /** @param array<string, mixed> $remote */
    private function apply(RazorpayRefund $refund, array $remote): void
    {
        $id = (string) ($remote['id'] ?? '');
        if (preg_match('/^rfnd_[A-Za-z0-9]{6,40}$/D', $id) !== 1 || (int) ($remote['amount'] ?? -1) !== $refund->getAmount()) {
            $refund->update(RazorpayRefund::STATUS_FAILED, null, 'Razorpay returned a refund that does not match.');
            $this->entityManager->flush();

            return;
        }

        $status = match ($remote['status'] ?? null) {
            'processed' => RazorpayRefund::STATUS_PROCESSED,
            'failed' => RazorpayRefund::STATUS_FAILED,
            default => RazorpayRefund::STATUS_PENDING,
        };
        $refund->update($status, $id, $status === RazorpayRefund::STATUS_FAILED ? 'Razorpay reported the refund as failed.' : null);

        if ($status === RazorpayRefund::STATUS_PROCESSED) {
            $this->completeRefundPayment($refund);
        }
        $this->entityManager->flush();
    }

    private function completeRefundPayment(RazorpayRefund $refund): void
    {
        if (!class_exists($this->refundPaymentClass)) {
            return;
        }
        $refundPayment = $this->entityManager->find($this->refundPaymentClass, $refund->getRefundPaymentId());
        if ($refundPayment !== null && $this->stateMachine->can($refundPayment, self::REFUND_PAYMENT_GRAPH, self::REFUND_PAYMENT_COMPLETE)) {
            $this->stateMachine->apply($refundPayment, self::REFUND_PAYMENT_GRAPH, self::REFUND_PAYMENT_COMPLETE);
        }
    }
}
