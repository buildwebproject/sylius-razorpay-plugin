<?php

declare(strict_types=1);

namespace BuildWebProject\SyliusRazorpayPlugin\PaymentRequest;

use BuildWebProject\SyliusRazorpayPlugin\Controller\CheckoutController;
use Sylius\Bundle\PaymentBundle\Provider\HttpResponseProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Sends the customer from "Pay" to the Razorpay checkout page and lets only this browser session open it. */
final readonly class HttpResponseProvider implements HttpResponseProviderInterface
{
    public function __construct(private UrlGeneratorInterface $urlGenerator)
    {
    }

    public function supports(Request $request, PaymentRequestInterface $paymentRequest): bool
    {
        return $paymentRequest->getAction() === PaymentRequestInterface::ACTION_CAPTURE;
    }

    public function getResponse(Request $request, PaymentRequestInterface $paymentRequest): Response
    {
        $hash = (string) $paymentRequest->getId();
        CheckoutController::grantAccess($request, $hash);

        $response = new RedirectResponse($this->urlGenerator->generate('buildwebproject_razorpay_checkout', [
            'hash' => $hash,
            '_locale' => $request->getLocale(),
        ]), Response::HTTP_SEE_OTHER);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
