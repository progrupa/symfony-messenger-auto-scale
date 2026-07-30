<?php

namespace Krak\SymfonyMessengerAutoScale;

interface BusyWorkerManager
{
    public function markBusy(): void;

    public function markIdle(): void;

    public function isProcessBusy(int $pid): bool;

    /**
     * Pids of every worker currently mid-message, as seen from THIS process's
     * point of view. Implementations must exclude markers whose process is gone:
     * a stale marker reported as busy would make a drain gate wait forever.
     *
     * @return int[]
     */
    public function busyPids(): array;

    public function cleanup(): void;
}
