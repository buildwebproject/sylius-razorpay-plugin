<?php

declare(strict_types=1);

namespace BuildWebProject\SyliusRazorpayPlugin\PaymentRequest;

use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

final class CommandProvider implements PaymentRequestCommandProviderInterface
{
    public function supports(PaymentRequestInterface $paymentRequest): bool
    {
        return \in_array($paymentRequest->getAction(), [PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::ACTION_STATUS], true);
    }

    public function provide(PaymentRequestInterface $paymentRequest): object
    {
        return match ($paymentRequest->getAction()) {
            PaymentRequestInterface::ACTION_CAPTURE => new CapturePaymentRequest($paymentRequest->getId()),
            PaymentRequestInterface::ACTION_STATUS => new StatusPaymentRequest($paymentRequest->getId()),
            default => throw new \LogicException(sprintf('Razorpay does not support the "%s" payment request action.', $paymentRequest->getAction())),
        };
    }
}
