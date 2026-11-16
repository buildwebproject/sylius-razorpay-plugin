<?php

declare(strict_types=1);

namespace BuildWebProject\SyliusRazorpayPlugin\Entity;

use Sylius\Component\Core\Model\PaymentInterface;

/**
 * Journal of one Sylius payment paid through Razorpay. It is written before the Razorpay order is created, so a timeout
 * or crash can always be reconciled by its receipt and never leads to a second order or an unrecorded payment.
 */
class RazorpayTransaction
{
    public const STATUS_CREATING = 'creating';

    /** The create-order call failed or timed out: Razorpay may or may not have the order. */
    public const STATUS_UNCERTAIN = 'uncertain';

    public const STATUS_CREATED = 'created';

    public const STATUS_CAPTURED = 'captured';

    /** Money arrived but does not match the order (amount, duplicate, cancelled order): staff must check. */
    public const STATUS_REVIEW = 'review';

    private ?int $id = null;

    private ?string $razorpayOrderId = null;

    private ?string $razorpayPaymentId = null;

    private string $status = self::STATUS_CREATING;

    private ?string $reviewReason = null;

    private \DateTimeImmutable $createdAt;

    private \DateTimeImmutable $updatedAt;

    public function __construct(
        private PaymentInterface $payment,
        private string $receipt,
        private int $amount,
        private string $currencyCode,
        private string $accountFingerprint,
    ) {
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPayment(): PaymentInterface
    {
        return $this->payment;
    }

    public function getReceipt(): string
    {
        return $this->receipt;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getCurrencyCode(): string
    {
        return $this->currencyCode;
    }

    public function getAccountFingerprint(): string
    {
        return $this->accountFingerprint;
    }

    public function getRazorpayOrderId(): ?string
    {
        return $this->razorpayOrderId;
    }

    public function attachRazorpayOrder(string $orderId): void
    {
        $this->razorpayOrderId = $orderId;
        $this->setStatus(self::STATUS_CREATED);
    }

    public function getRazorpayPaymentId(): ?string
    {
        return $this->razorpayPaymentId;
    }

    public function markCaptured(string $paymentId): void
    {
        $this->razorpayPaymentId = $paymentId;
        $this->setStatus(self::STATUS_CAPTURED);
    }

    public function markForReview(string $reason, ?string $paymentId = null): void
    {
        $this->reviewReason = $reason;
        $this->razorpayPaymentId ??= $paymentId;
        $this->setStatus(self::STATUS_REVIEW);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getReviewReason(): ?string
    {
        return $this->reviewReason;
    }

    public function isFinal(): bool
    {
        return \in_array($this->status, [self::STATUS_CAPTURED, self::STATUS_REVIEW], true);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
