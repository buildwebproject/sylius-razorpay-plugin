<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use BuildWebProject\SyliusRazorpayPlugin\Client\RazorpayClient;
use BuildWebProject\SyliusRazorpayPlugin\Command\ReconcileCommand;
use BuildWebProject\SyliusRazorpayPlugin\Controller\CheckoutController;
use BuildWebProject\SyliusRazorpayPlugin\Controller\WebhookController;
use BuildWebProject\SyliusRazorpayPlugin\DependencyInjection\BuildWebProjectSyliusRazorpayExtension;
use BuildWebProject\SyliusRazorpayPlugin\Form\Type\RazorpayGatewayConfigurationType;
use BuildWebProject\SyliusRazorpayPlugin\Gateway\RazorpayConfig;
use BuildWebProject\SyliusRazorpayPlugin\Payment\PaymentProcessor;
use BuildWebProject\SyliusRazorpayPlugin\PaymentRequest\CapturePaymentRequestHandler;
use BuildWebProject\SyliusRazorpayPlugin\PaymentRequest\CommandProvider;
use BuildWebProject\SyliusRazorpayPlugin\PaymentRequest\HttpResponseProvider;
use BuildWebProject\SyliusRazorpayPlugin\PaymentRequest\StatusPaymentRequestHandler;
use BuildWebProject\SyliusRazorpayPlugin\Refund\RefundPaymentGeneratedListener;
use BuildWebProject\SyliusRazorpayPlugin\Refund\RefundProcessor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $p = 'buildwebproject_sylius_razorpay.';
    $services = $container->services()->defaults()->private();
    $logger = service('logger');
    $refunds = $builder->getParameter($p . 'refund_plugin_installed') && $builder->getParameter($p . 'refunds');

    $services->set(RazorpayClient::class)->args([param($p . 'api_base_url'), param($p . 'api_timeout'), null]);

    $services->set(PaymentProcessor::class)
        ->args([service('doctrine.orm.entity_manager'), service(RazorpayClient::class), service('sylius_abstraction.state_machine'), $logger])
        ->tag('monolog.logger', ['channel' => 'payment']);

    $services->set(RazorpayGatewayConfigurationType::class)
        ->tag('sylius.gateway_configuration_type', ['type' => RazorpayConfig::FACTORY, 'label' => 'buildwebproject_sylius_razorpay.gateway_factory.razorpay'])
        ->tag('form.type');

    $services->set(CommandProvider::class)
        ->tag('sylius.payment_request.command_provider', ['gateway_factory' => RazorpayConfig::FACTORY]);

    $services->set(HttpResponseProvider::class)
        ->args([service('router')])
        ->tag('sylius.payment_request.provider.http_response', ['gateway_factory' => RazorpayConfig::FACTORY]);

    $services->set(CapturePaymentRequestHandler::class)
        ->args([service('sylius.provider.payment_request'), service('sylius_abstraction.state_machine')])
        ->tag('messenger.message_handler', ['bus' => 'sylius.payment_request.command_bus']);

    $services->set(StatusPaymentRequestHandler::class)
        ->args([service('sylius.provider.payment_request'), service(PaymentProcessor::class), service('sylius_abstraction.state_machine'), $logger])
        ->tag('messenger.message_handler', ['bus' => 'sylius.payment_request.command_bus']);

    $services->set(CheckoutController::class)
        ->args([
            service('sylius.repository.payment_request'),
            service(PaymentProcessor::class),
            service('limiter.' . BuildWebProjectSyliusRazorpayExtension::RATE_LIMITER),
            service('translator'),
            $logger,
        ])
        ->call('setContainer', [service(\Psr\Container\ContainerInterface::class)])
        ->tag('controller.service_arguments')
        ->tag('container.service_subscriber')
        ->public();

    if ($refunds) {
        $services->set(RefundProcessor::class)
            ->args([service('doctrine.orm.entity_manager'), service(RazorpayClient::class), service('sylius_abstraction.state_machine'), $logger])
            ->tag('monolog.logger', ['channel' => 'payment']);

        $services->set(RefundPaymentGeneratedListener::class)
            ->args([service(RefundProcessor::class), service('sylius.repository.payment_method')])
            ->tag('messenger.message_handler', ['bus' => 'sylius.event_bus'])
            ->tag('kernel.event_subscriber');
    }

    $services->set(WebhookController::class)
        ->args([service('sylius.repository.payment_method'), service(PaymentProcessor::class), $refunds ? service(RefundProcessor::class) : null, $logger])
        ->tag('controller.service_arguments')
        ->public();

    $services->set(ReconcileCommand::class)
        ->args([service(PaymentProcessor::class), $refunds ? service(RefundProcessor::class) : null])
        ->tag('console.command');
};
