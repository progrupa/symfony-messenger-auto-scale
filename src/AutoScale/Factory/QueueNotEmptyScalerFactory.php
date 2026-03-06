<?php

namespace Krak\SymfonyMessengerAutoScale\AutoScale\Factory;

use Krak\SymfonyMessengerAutoScale\AutoScale\AutoScalerFactory;
use Krak\SymfonyMessengerAutoScale\AutoScale\AutoScalerType;
use Krak\SymfonyMessengerAutoScale\AutoScale\QueueNotEmptyAutoScaler;
use Krak\SymfonyMessengerAutoScale\AutoScaler;
use Krak\SymfonyMessengerAutoScale\AutoScalerConfig;

final class QueueNotEmptyScalerFactory implements AutoScalerFactory
{
    public static function getType(): string
    {
        return AutoScalerType::QUEUE_NOT_EMPTY;
    }

    public static function isWrapping(): bool
    {
        return false;
    }

    public function create(AutoScalerConfig $config, ?AutoScaler $subordinate): AutoScaler
    {
        return new QueueNotEmptyAutoScaler(
            new AutoScalerConfig($config->getType(), [
                QueueNotEmptyAutoScaler::PARAM_ALLOWED_OVERFLOW => $config->getParameter('allow_queued') ?? 0,
                QueueNotEmptyAutoScaler::PARAM_ALLOWED_OVERFLOW_PER_PROC => $config->getParameter('allow_queued_per_worker') ?? 0,
            ]),
            $subordinate
        );
    }
}
