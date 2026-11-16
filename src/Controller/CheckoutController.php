<?php

declare(strict_types=1);

namespace BuildWebProject\SyliusRazorpayPlugin\Controller;

use BuildWebProject\SyliusRazorpayPlugin\Client\RazorpayException;
use BuildWebProject\SyliusRazorpayPlugin\Gateway\RazorpayConfig;
use BuildWebProject\SyliusRazorpayPlugin\Payment\PaymentBusyException;
use BuildWebProject\SyliusRazorpayPlugin\Payment\PaymentNotPossibleException;
use BuildWebProject\SyliusRazorpayPlugin\Payment\PaymentProcessor;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The page that opens Razorpay Checkout. Only the browser session that pressed "Pay" may open it (the payment request
 * hash is granted in the session by HttpResponseProvider); every action is CSRF-protected and rate limited.
 */
final class CheckoutController extends AbstractController
{
    private const SESSION_GRANTS = '_buildwebproject_razorpay_requests';

    private const GRANT_SECONDS = 86400;

    /** @param RepositoryInterface<PaymentRequestInterface> $paymentRequestRepository */
    public function __construct(
        private readonly RepositoryInterface $paymentRequestRepository,
        private readonly PaymentProcessor $processor,
        private readonly RateLimiterFactory $limiter,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function grantAccess(Request $request, string $hash): void
    {
        $session = $request->getSession();
        $grants = $session->get(self::SESSION_GRANTS, []);
        $grants = \is_array($grants) ? $grants : [];
        $grants[$hash] = time();
        $session->set(self::SESSION_GRANTS, \array_slice($grants, -10, null, true));
    }

    public function __invoke(Request $request, string $hash): Response
    {
        $grants = $request->hasSession() ? $request->getSession()->get(self::SESSION_GRANTS, []) : [];
        $grantedAt = \is_array($grants) ? ($grants[$hash] ?? null) : null;
        if (!\is_int($grantedAt) || $grantedAt < time() - self::GRANT_SECONDS) {
            return $this->private(new Response($this->translator->trans('buildwebproject_sylius_razorpay.ui.open_from_order'), Response::HTTP_FORBIDDEN));
        }

        $paymentRequest = $this->paymentRequestRepository->find($hash);
        $payment = $paymentRequest?->getPayment();
        if (!$payment instanceof PaymentInterface || !RazorpayConfig::supports($payment->getMethod()) || !$payment->getOrder() instanceof OrderInterface) {
            throw $this->createNotFoundException();
        }
        /** @var OrderInterface $order */
        $order = $payment->getOrder();

        if ($request->isMethod('POST')) {
            return $this->private($this->handleAction($request, $hash, $payment, $order));
        }

        return $this->private($this->render('@BuildWebProjectSyliusRazorpayPlugin/shop/checkout.html.twig', [
            'order' => $order,
            'payment' => $payment,
            'hash' => $hash,
            'result' => $this->processor->result($payment),
        ]));
    }

    private function handleAction(Request $request, string $hash, PaymentInterface $payment, OrderInterface $order): JsonResponse
    {
        if (!$this->isCsrfTokenValid('buildwebproject_razorpay_' . $hash, $request->request->getString('_token'))) {
            return $this->error('buildwebproject_sylius_razorpay.ui.session_expired', Response::HTTP_FORBIDDEN);
        }
        if (!$this->limiter->create($hash . '|' . $request->getClientIp())->consume()->isAccepted()) {
            return $this->error('buildwebproject_sylius_razorpay.ui.too_many_requests', Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            switch ($request->request->getString('action')) {
                case 'start':
                    return new JsonResponse(['checkout' => $this->processor->start($payment)]);
                case 'verify':
                    $result = $this->processor->verify(
                        $payment,
                        $request->request->getString('razorpay_order_id'),
                        $request->request->getString('razorpay_payment_id'),
                        $request->request->getString('razorpay_signature'),
                    );
                    break;
                case 'status':
                    if (($transaction = $this->processor->findTransaction($payment)) !== null) {
                        $this->processor->synchronize($transaction);
                    }
                    $result = $this->processor->result($payment);
                    break;
                default:
                    return $this->error('buildwebproject_sylius_razorpay.ui.unknown_action', Response::HTTP_BAD_REQUEST);
            }
        } catch (PaymentBusyException|PaymentNotPossibleException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_CONFLICT);
        } catch (RazorpayException $exception) {
            $this->logger->warning('Razorpay checkout action failed: {message}', ['message' => $exception->getMessage()]);

            return $this->error('buildwebproject_sylius_razorpay.ui.not_confirmed_yet', Response::HTTP_CONFLICT);
        }

        return new JsonResponse([
            'result' => $result,
            // Sylius "after pay" runs a status request and shows the thank-you page (paid) or the order page.
            'redirect' => $result === PaymentProcessor::RESULT_PAID
                ? $this->generateUrl('sylius_shop_order_after_pay', ['hash' => $hash, '_locale' => $order->getLocaleCode()])
                : null,
        ]);
    }

    private function error(string $key, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $this->translator->trans($key)], $status);
    }

    private function private(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
