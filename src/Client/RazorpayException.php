<?php

declare(strict_types=1);

namespace BuildWebProject\SyliusRazorpayPlugin\Client;

final class RazorpayException extends \RuntimeException
{
    public function __construct(string $message, int $statusCode = 0, ?\Throwable $previous = null, private readonly string $errorCode = '')
    {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** True when Razorpay may or may not have done the work (timeout, 5xx): reconcile instead of retrying. */
    public function isUncertain(): bool
    {
        return $this->errorCode === 'NETWORK' || $this->getCode() >= 500;
    }
}
