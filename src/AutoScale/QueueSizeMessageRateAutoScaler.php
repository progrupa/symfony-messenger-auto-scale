<?php

namespace Krak\SymfonyMessengerAutoScale\AutoScale;

use Krak\SymfonyMessengerAutoScale\AutoScaler;

final class QueueSizeMessageRateAutoScaler extends BaseAutoScaler implements AutoScaler
{
    const PARAM_MESSAGE_RATE = 'message_rate';

    public function scale(AutoScaleRequest $autoScaleRequest): AutoScaleResponse {
        $expectedNumProcs = ceil($autoScaleRequest->sizeOfQueue() / $this->config->getParameter(self::PARAM_MESSAGE_RATE));
        return new AutoScaleResponse($autoScaleRequest->state(), $expectedNumProcs);
    }
}
