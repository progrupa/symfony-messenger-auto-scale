<?php

namespace Krak\SymfonyMessengerAutoScale\DependencyInjection;

use Krak\SymfonyMessengerAutoScale\AutoScale\AutoScalerFactory;
use Krak\SymfonyMessengerAutoScale\MessengerAutoScaleBundle;
use Krak\SymfonyMessengerAutoScale\PoolConfig;
use Krak\SymfonyMessengerAutoScale\SupervisorPoolConfig;
use Symfony\Bundle\FrameworkBundle;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class BuildSupervisorPoolConfigCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container) {
        $availableReceiverNames = $this->findAvailableReceiverNames($container);
        $this->registerMappedPoolConfigData($container, $availableReceiverNames);
        $container->setParameter('messenger_auto_scale.receiver_names', $availableReceiverNames);
    }

    /**
     * Certain help array structures need to built from the original pool config data. To make these structures shareable
     * we register them as services factories which are responsible for transforming the array of supervisor pool config
     * into the necessary structure.
     * @see BuildSupervisorPoolConfigCompilerPass::createSupervisorPoolConfigsFromArray()
     * @see BuildSupervisorPoolConfigCompilerPass::createReceiverToPoolMappingFromArray()
     */
    private function registerMappedPoolConfigData(ContainerBuilder $container, array $availableReceiverNames): void {
        $rawPoolConfig = $container->getParameter('krak.messenger_auto_scale.config.pools');
        $supervisorPoolConfigs = iterator_to_array($this->buildSupervisorPoolConfigs($rawPoolConfig, $availableReceiverNames, $container));
        $container->findDefinition('krak.messenger_auto_scale.supervisor_pool_configs')->addArgument($supervisorPoolConfigs);
        $container->findDefinition('krak.messenger_auto_scale.receiver_to_pool_mapping')->addArgument($supervisorPoolConfigs);
    }

    /** @return SupervisorPoolConfig[] */
    private function buildSupervisorPoolConfigs(array $rawPoolConfig, array $availableReceiverNames, ContainerBuilder $container): iterable {
        $claimedReceivers = [];

        foreach ($rawPoolConfig['pools'] as $poolName => $rawPool) {
            $receiverIds = $rawPool['receivers'];

            foreach ($receiverIds as $receiverId) {
                if (!in_array($receiverId, $availableReceiverNames, true)) {
                    throw new \LogicException(sprintf(
                        'Pool "%s" references receiver "%s" which is not defined in framework.messenger.transports. Available: %s',
                        $poolName, $receiverId, implode(', ', $availableReceiverNames)
                    ));
                }
                if (isset($claimedReceivers[$receiverId])) {
                    throw new \LogicException(sprintf(
                        'Pool "%s" references receiver "%s" which is already claimed by pool "%s". A receiver can only belong to one pool.',
                        $poolName, $receiverId, $claimedReceivers[$receiverId]
                    ));
                }
                $claimedReceivers[$receiverId] = $poolName;
            }

            $this->validateScalerChain($poolName, $rawPool['scalers'] ?? [], $container);

            yield ['name' => $poolName, 'poolConfig' => $rawPool, 'receiverIds' => $receiverIds];
        }

        if ($rawPoolConfig['must_match_all_receivers']) {
            $unmatchedReceivers = array_diff($availableReceiverNames, array_keys($claimedReceivers));
            if (count($unmatchedReceivers)) {
                throw new \LogicException('Some receivers were not matched by the pool config: ' . implode(', ', $unmatchedReceivers));
            }
        }
    }

    private function validateScalerChain(string $poolName, array $scalers, ContainerBuilder $container): void {
        if (empty($scalers)) {
            return;
        }

        $factoryClasses = $this->getScalerFactoryClasses($container);

        $hasBaseScaler = false;
        foreach ($scalers as $scaler) {
            $type = $scaler['type'];
            if (!isset($factoryClasses[$type])) {
                throw new \LogicException(sprintf(
                    'Pool "%s" references unknown scaler type "%s". Available types: %s',
                    $poolName, $type, implode(', ', array_keys($factoryClasses))
                ));
            }
            if (!$factoryClasses[$type]::isWrapping()) {
                $hasBaseScaler = true;
            }
        }

        if (!$hasBaseScaler) {
            throw new \LogicException(sprintf(
                'Pool "%s" has no base scaler. The scaler chain requires at least one non-wrapping scaler.',
                $poolName
            ));
        }
    }

    /** @return array<string, class-string<AutoScalerFactory>> type => factory class */
    private function getScalerFactoryClasses(ContainerBuilder $container): array
    {
        $factories = [];
        foreach ($container->findTaggedServiceIds(MessengerAutoScaleBundle::TAG_SCALER_FACTORY) as $id => $tags) {
            $class = $container->getDefinition($id)->getClass() ?? $id;
            $type = $class::getType();
            $factories[$type] = $class;
        }
        return $factories;
    }

    private function findAvailableReceiverNames(ContainerBuilder $container): array {
        $frameworkConfig = (new Processor())->processConfiguration(
            new FrameworkBundle\DependencyInjection\Configuration($container->getParameter('kernel.debug')),
            $container->getExtensionConfig('framework')
        );
        $transports = $frameworkConfig['messenger']['transports'] ?? [];

        return array_keys($transports);
    }

    public static function createSupervisorPoolConfigsFromArray(array $poolConfigs): array {
        return \array_map(function(array $pool) {
            return new SupervisorPoolConfig($pool['name'], PoolConfig::createFromOptionsArray($pool['poolConfig']), $pool['receiverIds']);
        }, $poolConfigs);
    }

    public static function createReceiverToPoolMappingFromArray(array $poolConfigs): array {
        $mapping = [];
        foreach ($poolConfigs as $pool) {
            foreach ($pool['receiverIds'] as $receiverId) {
                $mapping[$receiverId] = $pool['name'];
            }
        }
        return $mapping;
    }
}
