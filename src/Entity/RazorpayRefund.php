<?php

declare(strict_types=1);

namespace BuildWebProject\SyliusRazorpayPlugin\Entity;

/** One refund sent (or to be sent) to Razorpay for a Sylius RefundPlugin refund payment. */
class RazorpayRefund
{
    /** Recorded inside the credit-memo transaction; sent once that transaction is committed. */
    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENDING = 'sending';

    /** The refund call failed or timed out: Razorpay may or may not have the refund. */
    public const STATUS_UNCERTAIN = 'uncertain';

    /** Accepted by Razorpay, money not yet back with the customer. */
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    private ?int $id = null;

    private ?string $razorpayRefundId = null;

    private string $status = self::STATUS_QUEUED;

    private ?string $failureReason = null;

    private \DateTimeImmutable $createdAt;

    private \DateTimeImmutable $updatedAt;

    public function __construct(
        private int $refundPaymentId,
        private RazorpayTransaction $transaction,
        private int $amount,
        private string $currencyCode,
    ) {
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRefundPaymentId(): int
    {
        return $this->refundPaymentId;
    }

    public function getTransaction(): RazorpayTransaction
    {
        return $this->transaction;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getCurrencyCode(): string
    {
        return $this->currencyCode;
    }

    /** Idempotency key sent to Razorpay as the refund receipt. */
    public function getReceipt(): string
    {
        return 'sylius_refund_' . $this->refundPaymentId;
    }

    public function getRazorpayRefundId(): ?string
    {
        return $this->razorpayRefundId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function update(string $status, ?string $razorpayRefundId = null, ?string $failureReason = null): void
    {
        $this->status = $status;
        $this->razorpayRefundId = $razorpayRefundId ?? $this->razorpayRefundId;
        $this->failureReason = $failureReason;
        $this->updatedAt = new \DateTimeImmutable();
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
