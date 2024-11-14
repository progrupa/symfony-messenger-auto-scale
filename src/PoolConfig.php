<?php

namespace Krak\SymfonyMessengerAutoScale;

use Krak\SymfonyMessengerAutoScale\AutoScale\AutoScalerType;
use Krak\SymfonyMessengerAutoScale\AutoScale\DebouncingAutoScaler;
use Krak\SymfonyMessengerAutoScale\AutoScale\MinMaxClipAutoScaler;
use Krak\SymfonyMessengerAutoScale\AutoScale\QueueNotEmptyAutoScaler;
use Krak\SymfonyMessengerAutoScale\AutoScale\QueueSizeMessageRateAutoScaler;

final class PoolConfig implements \JsonSerializable
{
    private array $attributes;
    private array $scalers;

    private array $scalerConfigurations;

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
        return array_map(fn ($name) => $this->getScalerConfig($name), array_keys($this->scalers));
    }

    public function getScalerConfig(mixed $name): ?AutoScalerConfig
    {
        if (!isset($this->scalers[$name])) {
            return null;
        }

        if (!isset($this->scalerConfigurations[$name])) {
            $scalerConfig = $this->scalers[$name];
            $this->scalerConfigurations[$name] = match ($scalerConfig['type']) {
                AutoScalerType::QUEUE_SIZE => new AutoScalerConfig(
                    $scalerConfig['type'],
                    [QueueSizeMessageRateAutoScaler::PARAM_MESSAGE_RATE => $scalerConfig['message_rate'] ?? 1]
                ),
                AutoScalerType::QUEUE_NOT_EMPTY => new AutoScalerConfig(
                    $scalerConfig['type'],
                    [
                        QueueNotEmptyAutoScaler::PARAM_ALLOWED_OVERFLOW => $scalerConfig['allow_queued'] ?? 0,
                        QueueNotEmptyAutoScaler::PARAM_ALLOWED_OVERFLOW_PER_PROC => $scalerConfig['allow_queued_per_worker'] ?? 0
                    ]
                ),
                AutoScalerType::MIN_MAX => new AutoScalerConfig(
                    $scalerConfig['type'],
                    [
                        MinMaxClipAutoScaler::PARAM_MIN_PROCESS_COUNT => $scalerConfig['min_procs'] ?? null,
                        MinMaxClipAutoScaler::PARAM_MAX_PROCESS_COUNT => $scalerConfig['max_procs'] ?? null,
                    ]
                ),
                AutoScalerType::DEBOUNCE => new AutoScalerConfig(
                    $scalerConfig['type'],
                    [
                        DebouncingAutoScaler::PARAM_SCALE_UP_THRESHOLD => $scalerConfig['scale_up_threshold_seconds'] ?? null,
                        DebouncingAutoScaler::PARAM_SCALE_DOWN_THRESHOLD => $scalerConfig['scale_down_threshold_seconds'] ?? null,
                    ]
                ),
            };
        }

        return $this->scalerConfigurations[$name];
    }

    public function jsonSerialize() {
        return get_object_vars($this);
    }
}
