<?php

namespace Krak\SymfonyMessengerAutoScale\AutoScale;

use Krak\SymfonyMessengerAutoScale\AutoScaler;

final class DebouncingAutoScaler extends BaseAutoScaler implements AutoScaler
{
    const SCALE_UP = 'up';
    const SCALE_DOWN = 'down';

    const PARAM_SCALE_UP_THRESHOLD = 'scale_up_threshold';
    const PARAM_SCALE_DOWN_THRESHOLD = 'scale_down_threshold';

    public function scale(AutoScaleRequest $autoScaleRequest): AutoScaleResponse {
        $resp = $this->subordinateScale($autoScaleRequest);
        if ($autoScaleRequest->timeSinceLastCall() === null || $resp->expectedNumProcs() === $autoScaleRequest->numProcs() || $autoScaleRequest->numProcs() === 0) {
            return $this->respWithDebounceSinceNeededScale($resp, null, null);
        }

        $scaleDirection = $resp->expectedNumProcs() > $autoScaleRequest->numProcs() ? self::SCALE_UP : self::SCALE_DOWN;

        // number of seconds for a scale event to be active before allowing scale event
        $scaleThreshold = $scaleDirection === self::SCALE_UP
            ? $this->config->getParameter(self::PARAM_SCALE_UP_THRESHOLD)
            : $this->config->getParameter(self::PARAM_SCALE_DOWN_THRESHOLD);

        [$timeSinceNeededScale, $scaleDirectionSinceNeededScale] = $autoScaleRequest->state()['debounce_since_needed_scale'] ?? [null, null];

        $debouncedResp = $resp->withExpectedNumProcs($autoScaleRequest->numProcs());
        if ($timeSinceNeededScale === null || $scaleDirection !== $scaleDirectionSinceNeededScale) {
            return $this->respWithDebounceSinceNeededScale($debouncedResp, 0, $scaleDirection);
        }
        $updatedTimeSinceNeededScale = $timeSinceNeededScale + $autoScaleRequest->timeSinceLastCall();
        if ($updatedTimeSinceNeededScale < $scaleThreshold) {
            return $this->respWithDebounceSinceNeededScale($debouncedResp, $updatedTimeSinceNeededScale, $scaleDirection);
        }

        return $this->respWithDebounceSinceNeededScale($resp, null, null);
    }

    private function respWithDebounceSinceNeededScale(AutoScaleResponse $resp, ?int $timeSinceNeededScale, ?string $scaleDirection): AutoScaleResponse {
        return $resp->withAddedState(['debounce_since_needed_scale' => [$timeSinceNeededScale, $scaleDirection]]);
    }
}
