<?php

declare(strict_types=1);

namespace BuildWebProject\SyliusRazorpayPlugin\PaymentRequest;

use BuildWebProject\SyliusRazorpayPlugin\Client\RazorpayException;
use BuildWebProject\SyliusRazorpayPlugin\Payment\PaymentProcessor;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;

/** "After pay" status check: asks Razorpay for the current state before Sylius shows the thank-you or order page. */
final readonly class StatusPaymentRequestHandler
{
    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private PaymentProcessor $processor,
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(StatusPaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);
        $payment = $paymentRequest->getPayment();

        if ($payment instanceof PaymentInterface && ($transaction = $this->processor->findTransaction($payment)) !== null) {
            try {
                $this->processor->synchronize($transaction);
            } catch (RazorpayException $exception) {
                // The customer sees "payment not confirmed yet"; webhooks and the reconcile command finish the job.
                $this->logger->warning('Razorpay status check failed: {message}', ['message' => $exception->getMessage()]);
            }
        }

        if ($this->stateMachine->can($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE)) {
            $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, PaymentRequestTransitions::TRANSITION_COMPLETE);
        }
    }
}
