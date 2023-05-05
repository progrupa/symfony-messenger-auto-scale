<?php

namespace Krak\SymfonyMessengerAutoScale\AutoScale;

use Krak\SymfonyMessengerAutoScale\AutoScaler;

final class QueueSizeMessageRateAutoScaler implements AutoScaler
{
    /** Max number of messages allowed for a single proc */
    private int $messageRate;

    public function __construct(int $messageRate)
    {
        $this->messageRate = $messageRate;
    }

    public function scale(AutoScaleRequest $autoScaleRequest): AutoScaleResponse {
        $expectedNumProcs = ceil($autoScaleRequest->sizeOfQueue() / $this->messageRate);
        return new AutoScaleResponse($autoScaleRequest->state(), $expectedNumProcs);
    }
}
