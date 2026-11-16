<?php

declare(strict_types=1);

namespace BuildWebProject\SyliusRazorpayPlugin\DependencyInjection\Compiler;

use BuildWebProject\SyliusRazorpayPlugin\Gateway\RazorpayConfig;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Lets Sylius RefundPlugin offer "refund to Razorpay" on credit memos (adds razorpay to its supported gateways). */
final class RegisterRefundGatewayPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('sylius_refund.supported_gateways')
            || !$container->getParameter('buildwebproject_sylius_razorpay.refunds')) {
            return;
        }

        $gateways = (array) $container->getParameter('sylius_refund.supported_gateways');
        if (!\in_array(RazorpayConfig::FACTORY, $gateways, true)) {
            $gateways[] = RazorpayConfig::FACTORY;
            $container->setParameter('sylius_refund.supported_gateways', $gateways);
        }
    }
}
