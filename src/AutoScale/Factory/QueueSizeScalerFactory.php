<?php

namespace Krak\SymfonyMessengerAutoScale\AutoScale\Factory;

use Krak\SymfonyMessengerAutoScale\AutoScale\AutoScalerFactory;
use Krak\SymfonyMessengerAutoScale\AutoScale\AutoScalerType;
use Krak\SymfonyMessengerAutoScale\AutoScale\QueueSizeMessageRateAutoScaler;
use Krak\SymfonyMessengerAutoScale\AutoScaler;
use Krak\SymfonyMessengerAutoScale\AutoScalerConfig;

final class QueueSizeScalerFactory implements AutoScalerFactory
{
    public static function getType(): string
    {
        return AutoScalerType::QUEUE_SIZE;
    }

    public static function isWrapping(): bool
    {
        return false;
    }

    public function create(AutoScalerConfig $config, ?AutoScaler $subordinate): AutoScaler
    {
        return new QueueSizeMessageRateAutoScaler(
            new AutoScalerConfig($config->getType(), [
                QueueSizeMessageRateAutoScaler::PARAM_MESSAGE_RATE => $config->getParameter('message_rate') ?? 1,
            ])
        );
    }
}
