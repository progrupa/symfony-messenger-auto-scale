<?php

namespace Krak\SymfonyMessengerAutoScale\AutoScale;

use Krak\SymfonyMessengerAutoScale\AutoScaler;
use Krak\SymfonyMessengerAutoScale\AutoScalerConfig;

abstract class BaseAutoScaler implements AutoScaler
{
    protected AutoScalerConfig $config;
    protected ?AutoScaler $subordinate;

    public function __construct(AutoScalerConfig $config, ?AutoScaler $subordinate = null)
    {
        $this->config = $config;
        $this->subordinate = $subordinate;
    }

    public function subordinateScale(AutoScaleRequest $request): AutoScaleResponse
    {
        return $this->subordinate->scale($request);
    }
}