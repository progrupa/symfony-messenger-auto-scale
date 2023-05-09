<?php

namespace Krak\SymfonyMessengerAutoScale\AutoScale;

use Krak\SymfonyMessengerAutoScale\AutoScaler;

final class MinMaxClipAutoScaler extends BaseAutoScaler implements AutoScaler
{
    const PARAM_MIN_PROCESS_COUNT = 'min_process_count';
    const PARAM_MAX_PROCESS_COUNT = 'max_process_count';

    public function scale(AutoScaleRequest $autoScaleRequest): AutoScaleResponse {
        $autoScaleResponse = $this->subordinateScale($autoScaleRequest);
        return $autoScaleResponse->withExpectedNumProcs(
            min(
                $this->config->getParameter(self::PARAM_MAX_PROCESS_COUNT),
                max($autoScaleResponse->expectedNumProcs(), $this->config->getParameter(self::PARAM_MIN_PROCESS_COUNT))
            )
        );
    }
}
