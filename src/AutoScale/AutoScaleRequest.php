<?php

namespace Krak\SymfonyMessengerAutoScale\AutoScale;

use Krak\SymfonyMessengerAutoScale\PoolConfig;

final class AutoScaleRequest
{
    private $state;
    private $timeSinceLastCall;
    private $numProcs;
    private $sizeOfQueue;

    public function __construct(?array $state, ?int $timeSinceLastCall, int $numProcs, int $sizeOfQueue) {
        $this->state = $state;
        $this->timeSinceLastCall = $timeSinceLastCall;
        $this->numProcs = $numProcs;
        $this->sizeOfQueue = $sizeOfQueue;
    }

    public function state(): ?array {
        return $this->state;
    }

    public function timeSinceLastCall(): ?int {
        return $this->timeSinceLastCall;
    }

    public function numProcs(): int {
        return $this->numProcs;
    }

    public function sizeOfQueue(): int {
        return $this->sizeOfQueue;
    }
}
