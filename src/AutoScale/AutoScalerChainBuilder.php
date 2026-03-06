<?php

namespace Krak\SymfonyMessengerAutoScale\AutoScale;

use Krak\SymfonyMessengerAutoScale\AutoScaler;
use Krak\SymfonyMessengerAutoScale\AutoScalerConfig;
use Krak\SymfonyMessengerAutoScale\PoolConfig;

final class AutoScalerChainBuilder
{
    /** @var array<string, AutoScalerFactory> */
    private array $factories;

    /** @param iterable<AutoScalerFactory> $factories */
    public function __construct(iterable $factories)
    {
        $this->factories = [];
        foreach ($factories as $factory) {
            $type = $factory::getType();
            if (isset($this->factories[$type])) {
                throw new \LogicException(sprintf(
                    'Duplicate scaler factory type "%s" registered by %s and %s.',
                    $type, get_class($this->factories[$type]), get_class($factory)
                ));
            }
            $this->factories[$type] = $factory;
        }
    }

    public function build(PoolConfig $config): ?AutoScaler
    {
        $scaler = null;
        foreach (array_reverse($config->getScalerConfigs()) as $scalerConfig) {
            $factory = $this->getFactory($scalerConfig->getType());
            $scaler = $factory->create($scalerConfig, $scaler);
        }
        return $scaler;
    }

    /** Check if a scaler type is registered. */
    public function hasType(string $type): bool
    {
        return isset($this->factories[$type]);
    }

    /** Check if a scaler type is wrapping (requires subordinate). */
    public function isWrapping(string $type): bool
    {
        return $this->getFactory($type)::isWrapping();
    }

    private function getFactory(string $type): AutoScalerFactory
    {
        if (!isset($this->factories[$type])) {
            throw new \LogicException(sprintf(
                'Unknown scaler type "%s". Available types: %s',
                $type, implode(', ', array_keys($this->factories))
            ));
        }
        return $this->factories[$type];
    }
}
