<?php

declare(strict_types=1);

namespace BuildWebProject\SyliusRazorpayPlugin\DependencyInjection;

use Sylius\Bundle\CoreBundle\DependencyInjection\PrependDoctrineMigrationsTrait;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

final class BuildWebProjectSyliusRazorpayExtension extends Extension implements PrependExtensionInterface
{
    use PrependDoctrineMigrationsTrait;

    public const RATE_LIMITER = 'buildwebproject_razorpay_checkout';

    public function getAlias(): string
    {
        return 'buildwebproject_sylius_razorpay';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        foreach ($config as $key => $value) {
            $container->setParameter('buildwebproject_sylius_razorpay.' . $key, $value);
        }
        $container->setParameter(
            'buildwebproject_sylius_razorpay.refund_plugin_installed',
            interface_exists('Sylius\RefundPlugin\Entity\RefundPaymentInterface'),
        );

        (new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2) . '/config')))->load('services.php');
    }

    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'BuildWebProjectSyliusRazorpayPlugin' => [
                        'type' => 'xml',
                        'dir' => \dirname(__DIR__, 2) . '/config/doctrine',
                        'prefix' => 'BuildWebProject\SyliusRazorpayPlugin\Entity',
                        'is_bundle' => false,
                    ],
                ],
            ],
        ]);

        // Status checks and payment confirmations from one checkout page.
        $container->prependExtensionConfig('framework', [
            'rate_limiter' => [
                self::RATE_LIMITER => ['policy' => 'sliding_window', 'limit' => 30, 'interval' => '5 minutes'],
            ],
        ]);

        $this->prependDoctrineMigrations($container);
    }

    protected function getMigrationsNamespace(): string
    {
        return 'BuildWebProject\SyliusRazorpayPlugin\Migrations';
    }

    protected function getMigrationsDirectory(): string
    {
        return '@BuildWebProjectSyliusRazorpayPlugin/src/Migrations';
    }

    protected function getNamespacesOfMigrationsExecutedBefore(): array
    {
        return ['Sylius\Bundle\CoreBundle\Migrations'];
    }
}
