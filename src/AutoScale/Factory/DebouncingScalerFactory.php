<?php

namespace Krak\SymfonyMessengerAutoScale\AutoScale\Factory;

use Krak\SymfonyMessengerAutoScale\AutoScale\AutoScalerFactory;
use Krak\SymfonyMessengerAutoScale\AutoScale\AutoScalerType;
use Krak\SymfonyMessengerAutoScale\AutoScale\DebouncingAutoScaler;
use Krak\SymfonyMessengerAutoScale\AutoScaler;
use Krak\SymfonyMessengerAutoScale\AutoScalerConfig;

final class DebouncingScalerFactory implements AutoScalerFactory
{
    public static function getType(): string
    {
        return AutoScalerType::DEBOUNCE;
    }

    public static function isWrapping(): bool
    {
        return true;
    }

    public function create(AutoScalerConfig $config, ?AutoScaler $subordinate): AutoScaler
    {
        return new DebouncingAutoScaler(
            new AutoScalerConfig($config->getType(), [
                DebouncingAutoScaler::PARAM_SCALE_UP_THRESHOLD => $config->getParameter('scale_up_threshold_seconds') ?? null,
                DebouncingAutoScaler::PARAM_SCALE_DOWN_THRESHOLD => $config->getParameter('scale_down_threshold_seconds') ?? null,
            ]),
            $subordinate
        );
    }
}
