<?php

namespace Krak\SymfonyMessengerAutoScale\AutoScale;

use Krak\SymfonyMessengerAutoScale\AutoScaler;

class QueueNotEmptyAutoScaler extends BaseAutoScaler implements AutoScaler
{
    const PARAM_ALLOWED_OVERFLOW = 'allowed-overflow';
    const PARAM_ALLOWED_OVERFLOW_PER_PROC = 'allowed-overflow-per-proc';
    public function scale(AutoScaleRequest $autoScaleRequest): AutoScaleResponse
    {
        $allowedOverflow = $this->config->getParameter(self::PARAM_ALLOWED_OVERFLOW) > 0 ?
            $this->config->getParameter(self::PARAM_ALLOWED_OVERFLOW) :
            ($this->config->getParameter(self::PARAM_ALLOWED_OVERFLOW_PER_PROC) * $autoScaleRequest->numProcs()
        );
        $increment = $autoScaleRequest->sizeOfQueue() > $allowedOverflow ? 1 : ($autoScaleRequest->sizeOfQueue() == 0 ? -1 : 0);
        $expectedNumProcs = max(0, $autoScaleRequest->numProcs() + $increment);

        return new AutoScaleResponse($autoScaleRequest->state(), $expectedNumProcs);
    }
}