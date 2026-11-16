<?php

declare(strict_types=1);

namespace BuildWebProject\SyliusRazorpayPlugin\Payment;

use BuildWebProject\SyliusRazorpayPlugin\Client\RazorpayClient;
use BuildWebProject\SyliusRazorpayPlugin\Client\RazorpayException;
use BuildWebProject\SyliusRazorpayPlugin\Entity\RazorpayTransaction;
use BuildWebProject\SyliusRazorpayPlugin\Gateway\RazorpayConfig;
use BuildWebProject\SyliusRazorpayPlugin\Gateway\Signature;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\PaymentTransitions;

/**
 * Creates Razorpay orders, verifies checkout results and moves the Sylius payment to "completed" only after Razorpay
 * confirms a captured payment with the expected amount and currency for that exact order.
 */
final class PaymentProcessor
{
    public const RESULT_PAID = 'paid';

    public const RESULT_REVIEW = 'review';

    public const RESULT_PENDING = 'pending';

    public const RESULT_READY = 'ready';

    /** A "creating" record older than this belongs to a request that died mid-call. */
    private const STALE_CREATING_SECONDS = 60;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RazorpayClient $client,
        private readonly StateMachineInterface $stateMachine,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function findTransaction(PaymentInterface $payment): ?RazorpayTransaction
    {
        return $this->entityManager->getRepository(RazorpayTransaction::class)->findOneBy(['payment' => $payment]);
    }

    public function findTransactionByOrderId(string $razorpayOrderId): ?RazorpayTransaction
    {
        return $this->entityManager->getRepository(RazorpayTransaction::class)->findOneBy(['razorpayOrderId' => $razorpayOrderId]);
    }

    /**
     * Returns the options for Razorpay Checkout (checkout.js). Must run outside a database transaction: the journal row
     * is committed before the Razorpay order is created, so a timeout can be reconciled by its receipt.
     *
     * @return array<string, mixed>
     */
    public function start(PaymentInterface $payment): array
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException('Razorpay orders must be created outside a database transaction.');
        }

        $this->entityManager->refresh($payment);
        $order = $this->assertPayable($payment);
        $config = RazorpayConfig::fromPaymentMethod($payment->getMethod());

        $transaction = $this->findTransaction($payment);
        if ($transaction === null) {
            // Only the request that inserted the journal row creates the Razorpay order.
            $transaction = $this->createTransaction($payment, $config);
            $this->createRemoteOrder($transaction, $order, $config);
        }
        if ($transaction->getAccountFingerprint() !== $config->accountFingerprint()) {
            throw new PaymentNotPossibleException('The Razorpay account of this payment method changed after the payment started. Contact the store.');
        }
        if ($transaction->getAmount() !== $payment->getAmount() || $transaction->getCurrencyCode() !== $payment->getCurrencyCode()) {
            throw new PaymentNotPossibleException('The order amount changed after the payment started. Contact the store.');
        }

        if ($transaction->getStatus() === RazorpayTransaction::STATUS_CREATING) {
            if (time() - $transaction->getUpdatedAt()->getTimestamp() < self::STALE_CREATING_SECONDS) {
                throw new PaymentBusyException('The payment is being prepared. Please wait a moment.');
            }
            // The request that was creating the order died mid-call.
            $transaction->setStatus(RazorpayTransaction::STATUS_UNCERTAIN);
            $this->entityManager->flush();
        }

        if ($transaction->getStatus() === RazorpayTransaction::STATUS_UNCERTAIN) {
            $this->recoverRemoteOrder($transaction, $config);
            if ($transaction->getRazorpayOrderId() === null && !$transaction->isFinal() && $this->claimForCreation($transaction)) {
                $this->createRemoteOrder($transaction, $order, $config);
            }
        }

        if ($transaction->isFinal()) {
            throw new PaymentNotPossibleException('This payment is already completed or under review.');
        }
        if ($transaction->getRazorpayOrderId() === null) {
            throw new PaymentBusyException('The payment is being prepared. Please check again shortly.');
        }

        return $this->checkoutOptions($transaction, $order, $config);
    }

    /** Called with the result of Razorpay Checkout; the signature proves it came from Razorpay for our order. */
    public function verify(PaymentInterface $payment, string $razorpayOrderId, string $razorpayPaymentId, string $signature): string
    {
        $transaction = $this->findTransaction($payment);
        if ($transaction === null || $transaction->getRazorpayOrderId() === null || !hash_equals($transaction->getRazorpayOrderId(), $razorpayOrderId)) {
            throw new PaymentNotPossibleException('This payment does not belong to the order.');
        }

        $config = RazorpayConfig::fromPaymentMethod($payment->getMethod());
        if (!Signature::isValidPayment($razorpayOrderId, $razorpayPaymentId, $signature, $config)) {
            $this->logger->warning('Razorpay checkout signature mismatch for payment {payment}.', ['payment' => $payment->getId()]);

            throw new PaymentNotPossibleException('The payment could not be verified.');
        }

        // The signature only says Razorpay saw a payment; the amount and capture state are always read from the API.
        $this->synchronize($transaction);

        return $this->result($payment);
    }

    /** Reads the order's payments from Razorpay and completes the Sylius payment when one is captured. */
    public function synchronize(RazorpayTransaction $transaction): void
    {
        if ($transaction->isFinal()) {
            return;
        }

        $payment = $transaction->getPayment();
        $config = RazorpayConfig::fromPaymentMethod($payment->getMethod());

        if ($transaction->getRazorpayOrderId() === null) {
            $this->recoverRemoteOrder($transaction, $config);
            if ($transaction->getRazorpayOrderId() === null) {
                return;
            }
        }

        $captured = [];
        foreach ($this->client->fetchOrderPayments($config, (string) $transaction->getRazorpayOrderId()) as $remote) {
            $remote = $this->captureIfAuthorized($config, $transaction, $remote);
            if (($remote['status'] ?? null) === 'captured' || ($remote['status'] ?? null) === 'refunded') {
                $captured[] = $remote;
            }
        }

        if ($captured === []) {
            return;
        }

        foreach ($captured as $remote) {
            if (($remote['order_id'] ?? null) !== $transaction->getRazorpayOrderId()
                || (int) ($remote['amount'] ?? -1) !== $transaction->getAmount()
                || strtoupper((string) ($remote['currency'] ?? '')) !== $transaction->getCurrencyCode()) {
                $this->review($transaction, 'Captured amount or currency differs from the order.', (string) ($remote['id'] ?? ''));

                return;
            }
        }

        $this->completeLocalPayment($transaction, $captured[0]);

        if (\count($captured) > 1) {
            $this->review($transaction, 'The customer paid more than once for this order; refund the extra payment in Razorpay.', (string) $captured[1]['id']);
        }
    }

    public function result(PaymentInterface $payment): string
    {
        $transaction = $this->findTransaction($payment);
        if ($transaction?->getStatus() === RazorpayTransaction::STATUS_REVIEW) {
            return self::RESULT_REVIEW;
        }
        if (\in_array($payment->getState(), [PaymentInterface::STATE_COMPLETED, PaymentInterface::STATE_REFUNDED], true)) {
            return self::RESULT_PAID;
        }

        return $transaction?->getRazorpayOrderId() !== null ? self::RESULT_PENDING : self::RESULT_READY;
    }

    /** @return list<RazorpayTransaction> unfinished transactions worth checking again */
    public function findUnfinished(\DateTimeImmutable $notAfter, \DateTimeImmutable $notBefore): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(RazorpayTransaction::class, 't')
            ->where('t.status IN (:statuses)')
            ->andWhere('t.updatedAt <= :notAfter')
            ->andWhere('t.createdAt >= :notBefore')
            ->setParameter('statuses', [RazorpayTransaction::STATUS_CREATING, RazorpayTransaction::STATUS_UNCERTAIN, RazorpayTransaction::STATUS_CREATED])
            ->setParameter('notAfter', $notAfter)
            ->setParameter('notBefore', $notBefore)
            ->orderBy('t.id')
            ->setMaxResults(200)
            ->getQuery()
            ->getResult();
    }

    private function assertPayable(PaymentInterface $payment): OrderInterface
    {
        $order = $payment->getOrder();
        $method = $payment->getMethod();
        if (!$order instanceof OrderInterface || !$method instanceof PaymentMethodInterface || !RazorpayConfig::supports($method)) {
            throw new PaymentNotPossibleException('This order cannot be paid with Razorpay.');
        }

        $channel = $order->getChannel();
        if (!$method->isEnabled() || $channel === null || !$method->hasChannel($channel)) {
            throw new PaymentNotPossibleException('Razorpay is not available for this store.');
        }
        if ($order->getCheckoutState() !== 'completed' || $order->getState() !== OrderInterface::STATE_NEW
            || \in_array($order->getPaymentState(), ['paid', 'refunded', 'partially_refunded'], true)) {
            throw new PaymentNotPossibleException('This order can no longer be paid online. Check its status on the order page.');
        }
        if (!\in_array($payment->getState(), [PaymentInterface::STATE_NEW, PaymentInterface::STATE_PROCESSING], true)) {
            throw new PaymentNotPossibleException('This payment is no longer open.');
        }
        if ($payment->getAmount() === null || $payment->getAmount() < 100 || $payment->getCurrencyCode() === null) {
            throw new PaymentNotPossibleException('The payment amount is too small for Razorpay.');
        }

        return $order;
    }

    private function createTransaction(PaymentInterface $payment, RazorpayConfig $config): RazorpayTransaction
    {
        $transaction = new RazorpayTransaction(
            $payment,
            'sy_' . bin2hex(random_bytes(16)),
            (int) $payment->getAmount(),
            strtoupper((string) $payment->getCurrencyCode()),
            $config->accountFingerprint(),
        );

        try {
            $this->entityManager->persist($transaction);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // A parallel request created it first.
            throw new PaymentBusyException('The payment is being prepared. Please wait a moment.');
        }

        // Only the request that inserted the row may create the Razorpay order.
        return $transaction;
    }

    /** Atomically takes the right to retry the create-order call, so two requests never both create an order. */
    private function claimForCreation(RazorpayTransaction $transaction): bool
    {
        $claimed = $this->entityManager->getConnection()->executeStatement(
            'UPDATE bwp_razorpay_transaction SET status = ?, updated_at = ? WHERE id = ? AND status = ? AND razorpay_order_id IS NULL',
            [RazorpayTransaction::STATUS_CREATING, (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $transaction->getId(), RazorpayTransaction::STATUS_UNCERTAIN],
        );
        $this->entityManager->refresh($transaction);

        return $claimed === 1;
    }

    private function createRemoteOrder(RazorpayTransaction $transaction, OrderInterface $order, RazorpayConfig $config): void
    {
        try {
            $remote = $this->client->createOrder($config, $transaction->getAmount(), $transaction->getCurrencyCode(), $transaction->getReceipt(), [
                'sylius_order' => (string) $order->getNumber(),
                'sylius_payment' => (string) $transaction->getPayment()->getId(),
            ]);
        } catch (RazorpayException $exception) {
            $this->logger->error('Razorpay order creation failed for payment {payment}: {message}', ['payment' => $transaction->getPayment()->getId(), 'message' => $exception->getMessage()]);
            $transaction->setStatus(RazorpayTransaction::STATUS_UNCERTAIN);
            $this->entityManager->flush();

            throw new PaymentBusyException('Razorpay could not be reached. Please try again in a moment.', 0, $exception);
        }

        $this->attachRemoteOrder($transaction, $remote);
    }

    /** After a timeout: find the order Razorpay may have created with our receipt. */
    private function recoverRemoteOrder(RazorpayTransaction $transaction, RazorpayConfig $config): void
    {
        $matches = array_values(array_filter(
            $this->client->findOrdersByReceipt($config, $transaction->getReceipt()),
            static fn (array $remote): bool => ($remote['receipt'] ?? null) === $transaction->getReceipt(),
        ));

        if (\count($matches) > 1) {
            $this->review($transaction, 'Razorpay has more than one order for this payment.');

            return;
        }
        if (\count($matches) === 1) {
            $this->attachRemoteOrder($transaction, $matches[0]);
        }
    }

    /** @param array<string, mixed> $remote */
    private function attachRemoteOrder(RazorpayTransaction $transaction, array $remote): void
    {
        $id = (string) ($remote['id'] ?? '');
        if (preg_match('/^order_[A-Za-z0-9]{6,40}$/D', $id) !== 1
            || ($remote['receipt'] ?? null) !== $transaction->getReceipt()
            || (int) ($remote['amount'] ?? -1) !== $transaction->getAmount()
            || strtoupper((string) ($remote['currency'] ?? '')) !== $transaction->getCurrencyCode()) {
            $this->review($transaction, 'The Razorpay order does not match this payment.');

            throw new PaymentNotPossibleException('The payment needs to be checked by the store.');
        }

        $transaction->attachRazorpayOrder($id);
        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $remote
     *
     * @return array<string, mixed>
     */
    private function captureIfAuthorized(RazorpayConfig $config, RazorpayTransaction $transaction, array $remote): array
    {
        if (($remote['status'] ?? null) !== 'authorized' || (int) ($remote['amount'] ?? -1) !== $transaction->getAmount()) {
            return $remote;
        }

        try {
            return $this->client->capturePayment($config, (string) $remote['id'], $transaction->getAmount(), $transaction->getCurrencyCode());
        } catch (RazorpayException) {
            // Already captured by Razorpay's automatic capture or a parallel request: read the current state.
            return $this->client->fetchPayment($config, (string) $remote['id']);
        }
    }

    /** @param array<string, mixed> $remote */
    private function completeLocalPayment(RazorpayTransaction $transaction, array $remote): void
    {
        $this->entityManager->wrapInTransaction(function () use ($transaction, $remote): void {
            $payment = $transaction->getPayment();
            // Row lock: webhooks, the checkout page and the reconcile command may arrive at the same moment.
            $this->entityManager->refresh($payment, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($transaction);
            $order = $payment->getOrder();
            $remoteId = (string) $remote['id'];

            if ($transaction->isFinal()) {
                return;
            }
            if (\in_array($payment->getState(), [PaymentInterface::STATE_COMPLETED, PaymentInterface::STATE_REFUNDED], true)) {
                $transaction->markCaptured($remoteId);

                return;
            }
            if (!$order instanceof OrderInterface || $order->getState() === OrderInterface::STATE_CANCELLED
                || !$this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE)) {
                $transaction->markForReview('Money was captured after the order or payment was cancelled; refund it in Razorpay or restore the order.', $remoteId);

                return;
            }
            if ($payment->getAmount() !== $transaction->getAmount() || $payment->getCurrencyCode() !== $transaction->getCurrencyCode()) {
                $transaction->markForReview('The Sylius payment amount changed after Razorpay checkout started.', $remoteId);

                return;
            }
            foreach ($order->getPayments() as $other) {
                if ($other !== $payment && $other->getState() === PaymentInterface::STATE_COMPLETED) {
                    $transaction->markForReview('The order was already paid by another payment; refund this Razorpay payment.', $remoteId);

                    return;
                }
            }

            $details = $payment->getDetails();
            $details['razorpay'] = array_filter([
                'order_id' => $transaction->getRazorpayOrderId(),
                'payment_id' => $remoteId,
                'method' => $remote['method'] ?? null,
                'vpa' => $remote['vpa'] ?? null,
                'bank' => $remote['bank'] ?? null,
                'wallet' => $remote['wallet'] ?? null,
                'card_network' => \is_array($remote['card'] ?? null) ? ($remote['card']['network'] ?? null) : null,
                'card_last4' => \is_array($remote['card'] ?? null) ? ($remote['card']['last4'] ?? null) : null,
                'fee' => $remote['fee'] ?? null,
                'tax' => $remote['tax'] ?? null,
                'captured_at' => $remote['created_at'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
            $payment->setDetails($details);

            $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);
            $transaction->markCaptured($remoteId);

            foreach ($payment->getPaymentRequests() as $request) {
                if (\in_array($request->getAction(), [PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::ACTION_AUTHORIZE], true)
                    && $this->stateMachine->can($request, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE)) {
                    $this->stateMachine->apply($request, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);
                }
            }

            $this->logger->info('Razorpay payment {razorpay} completed Sylius payment {payment} of order {order}.', [
                'razorpay' => $remoteId,
                'payment' => $payment->getId(),
                'order' => $order->getNumber(),
            ]);
        });
    }

    private function review(RazorpayTransaction $transaction, string $reason, ?string $paymentId = null): void
    {
        $transaction->markForReview($reason, $paymentId === '' ? null : $paymentId);
        $this->entityManager->flush();
        $this->logger->error('Razorpay payment for Sylius payment {payment} needs review: {reason}', ['payment' => $transaction->getPayment()->getId(), 'reason' => $reason]);
    }

    /** @return array<string, mixed> */
    private function checkoutOptions(RazorpayTransaction $transaction, OrderInterface $order, RazorpayConfig $config): array
    {
        $address = $order->getBillingAddress();
        $email = (string) $order->getCustomer()?->getEmail();
        $options = [
            'key' => $config->keyId,
            'order_id' => $transaction->getRazorpayOrderId(),
            'amount' => $transaction->getAmount(),
            'currency' => $transaction->getCurrencyCode(),
            'name' => $config->merchantName !== '' ? $config->merchantName : (string) $order->getChannel()?->getName(),
            'description' => 'Order #' . $order->getNumber(),
            'prefill' => array_filter([
                'name' => trim(($address?->getFirstName() ?? '') . ' ' . ($address?->getLastName() ?? '')),
                'email' => $email,
                'contact' => (string) $address?->getPhoneNumber(),
            ]),
            'notes' => ['sylius_order' => (string) $order->getNumber()],
            'theme' => ['color' => $config->themeColor],
        ];
        if ($config->hiddenMethods() !== []) {
            $options['config'] = ['display' => ['hide' => $config->hiddenMethods(), 'preferences' => ['show_default_blocks' => true]]];
        }

        return $options;
    }
}
