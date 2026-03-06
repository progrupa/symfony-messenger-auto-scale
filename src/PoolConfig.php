<?php

namespace Krak\SymfonyMessengerAutoScale;

final class PoolConfig implements \JsonSerializable
{
    private array $attributes;
    private array $scalers;

    public function __construct(array $scalers, array $attributes = []) {
        $this->scalers = $scalers;
        $this->attributes = $attributes;
    }

    public static function createFromOptionsArray(array $poolConfig): self {
        return new self(
            $poolConfig['scalers'],
            array_diff_key($poolConfig, ['scalers' => true])
        );
    }

    public function attributes(): array {
        return $this->attributes;
    }

    /** @return array<AutoScalerConfig> */
    public function getScalerConfigs(): array
    {
        return array_map(
            fn ($name) => $this->getScalerConfig($name),
            array_keys($this->scalers)
        );
    }

    public function getScalerConfig(mixed $name): ?AutoScalerConfig
    {
        if (!isset($this->scalers[$name])) {
            return null;
        }

        $scaler = $this->scalers[$name];
        $type = $scaler['type'];
        $params = array_diff_key($scaler, ['type' => true]);

        return new AutoScalerConfig($type, $params);
    }

    public function jsonSerialize(): mixed {
        return get_object_vars($this);
    }
}
