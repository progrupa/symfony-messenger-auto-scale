<?php

namespace Krak\SymfonyMessengerAutoScale;

interface BusyWorkerManager
{
    public function markBusy(): void;

    public function markIdle(): void;

    public function isProcessBusy(int $pid): bool;

    public function cleanup(): void;
}
