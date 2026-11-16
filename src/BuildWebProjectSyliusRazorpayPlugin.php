<?php

declare(strict_types=1);

namespace BuildWebProject\SyliusRazorpayPlugin;

use BuildWebProject\SyliusRazorpayPlugin\DependencyInjection\BuildWebProjectSyliusRazorpayExtension;
use BuildWebProject\SyliusRazorpayPlugin\DependencyInjection\Compiler\RegisterRefundGatewayPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Sylius\Bundle\CoreBundle\Application\SyliusPluginTrait;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class BuildWebProjectSyliusRazorpayPlugin extends Bundle
{
    use SyliusPluginTrait;

    private ?ExtensionInterface $razorpayExtension = null;

    /** Configuration key: buildwebproject_sylius_razorpay */
    public function getContainerExtension(): ExtensionInterface
    {
        return $this->razorpayExtension ??= new BuildWebProjectSyliusRazorpayExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new RegisterRefundGatewayPass());
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
