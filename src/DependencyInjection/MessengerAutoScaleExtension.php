<?php

namespace Krak\SymfonyMessengerAutoScale\DependencyInjection;

use Krak\SymfonyMessengerAutoScale\BusyWorkerManager;
use Krak\SymfonyMessengerAutoScale\PidFileManager;
use Krak\SymfonyMessengerAutoScale\ProcessManager\SymfonyMessengerProcessManagerFactory;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

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

        $pidFileManagerDef = new Definition(PidFileManager::class, [
            $processedConfig['busy_dir'],
            $processedConfig['busy_file_prefix'],
        ]);
        $pidFileManagerDef->setPublic(false);
        $container->setDefinition(PidFileManager::class, $pidFileManagerDef);

        // Alias the interface to PidFileManager. App code can override by
        // registering its own BusyWorkerManager implementation.
        $container->setAlias(BusyWorkerManager::class, PidFileManager::class);

        $container->findDefinition(SymfonyMessengerProcessManagerFactory::class)
            ->setArgument('$pathToConsole', $processedConfig['console_path'])
            ->setArgument('$busyWorkerManager', new Reference(BusyWorkerManager::class));
    }

    private function loadServices(ContainerBuilder $container): void {
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.xml');
    }

}
