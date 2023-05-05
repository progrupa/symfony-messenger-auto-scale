<?php

namespace Krak\SymfonyMessengerAutoScale\AutoScale;

use Krak\SymfonyMessengerAutoScale\AutoScaler;

abstract class IntermediateAutoScaler implements AutoScaler
{
    protected AutoScaler $subordinate;

    public function __construct(AutoScaler $subordinate)
    {
        $this->subordinate = $subordinate;
    }

    public function subordinateScale(AutoScaleRequest $request): AutoScaleResponse
    {
        return $this->subordinate->scale($request);
    }
}