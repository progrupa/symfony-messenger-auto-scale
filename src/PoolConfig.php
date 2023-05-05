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

    public function addScaler(AutoScaler $scaler): static
    {
        $this->scalers[] = $scaler;
        return $this;
    }

    public function getScalers(): array
    {
        return $this->scalers;
    }

    public function jsonSerialize() {
        return get_object_vars($this);
    }
}
