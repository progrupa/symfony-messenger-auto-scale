<?php

namespace Krak\SymfonyMessengerAutoScale\AutoScale\Factory;

use Krak\SymfonyMessengerAutoScale\AutoScale\AutoScalerFactory;
use Krak\SymfonyMessengerAutoScale\AutoScale\AutoScalerType;
use Krak\SymfonyMessengerAutoScale\AutoScale\MinMaxClipAutoScaler;
use Krak\SymfonyMessengerAutoScale\AutoScaler;
use Krak\SymfonyMessengerAutoScale\AutoScalerConfig;

final class MinMaxScalerFactory implements AutoScalerFactory
{
    public static function getType(): string
    {
        return AutoScalerType::MIN_MAX;
    }

    public static function isWrapping(): bool
    {
        return true;
    }

    public function create(AutoScalerConfig $config, ?AutoScaler $subordinate): AutoScaler
    {
        return new MinMaxClipAutoScaler(
            new AutoScalerConfig($config->getType(), [
                MinMaxClipAutoScaler::PARAM_MIN_PROCESS_COUNT => $config->getParameter('min_procs') ?? null,
                MinMaxClipAutoScaler::PARAM_MAX_PROCESS_COUNT => $config->getParameter('max_procs') ?? null,
            ]),
            $subordinate
        );
    }
}
