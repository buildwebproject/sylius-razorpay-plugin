<?php

declare(strict_types=1);

namespace BuildWebProject\SyliusRazorpayPlugin\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('buildwebproject_sylius_razorpay');

        $treeBuilder->getRootNode()
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('api_base_url')
                    ->info('Razorpay API base URL. Change only for testing against a mock server.')
                    ->defaultValue('https://api.razorpay.com/v1/')
                    ->cannotBeEmpty()
                ->end()
                ->floatNode('api_timeout')
                    ->info('Seconds to wait for a Razorpay API response.')
                    ->defaultValue(10.0)
                    ->min(2.0)
                    ->max(60.0)
                ->end()
                ->booleanNode('refunds')
                    ->info('Send Sylius RefundPlugin refunds to Razorpay automatically (when sylius/refund-plugin is installed).')
                    ->defaultTrue()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
