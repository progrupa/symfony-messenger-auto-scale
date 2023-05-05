<?php

namespace Krak\SymfonyMessengerAutoScale\AutoScale;

use Krak\SymfonyMessengerAutoScale\AutoScaler;

final class MinMaxClipAutoScaler extends IntermediateAutoScaler implements AutoScaler
{
    private ?int $minProcessCount;
    private ?int $maxProcessCount;

    public function __construct(AutoScaler $subordinate, ?int $minProcessCount, ?int $maxProcessCount)
    {
        parent::__construct($subordinate);
        $this->minProcessCount = $minProcessCount ?? 0;
        $this->maxProcessCount = $maxProcessCount;
    }

    public function scale(AutoScaleRequest $autoScaleRequest): AutoScaleResponse {
        $autoScaleResponse = $this->subordinateScale($autoScaleRequest);
        return $autoScaleResponse->withExpectedNumProcs(min($this->maxProcessCount, max($autoScaleResponse->expectedNumProcs(), $this->minProcessCount)));
    }
}
