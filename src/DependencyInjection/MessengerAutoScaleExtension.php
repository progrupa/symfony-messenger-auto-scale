<?php

namespace Krak\SymfonyMessengerAutoScale\DependencyInjection;

use Krak\SymfonyMessengerAutoScale\ProcessManager\SymfonyMessengerProcessManagerFactory;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

class MessengerAutoScaleExtension extends Extension
{
    public function getAlias(): string {
        return 'messenger_auto_scale';
    }

    /** @param mixed[] $configs */
    public function load(array $configs, ContainerBuilder $container): void {
        $configuration = new Configuration();
        $processedConfig = $this->processConfiguration($configuration, $configs);
        $this->loadServices($container);

        // processed pool config to be accessible as a parameter.
        $container->setParameter('krak.messenger_auto_scale.config.pools', $processedConfig);
        $container->findDefinition(SymfonyMessengerProcessManagerFactory::class)
            ->addArgument($processedConfig['console_path']);
    }

    private function loadServices(ContainerBuilder $container): void {
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.xml');
    }

}