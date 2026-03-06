<?php

namespace Krak\SymfonyMessengerAutoScale\Tests\Feature\ProcessManager;

use Krak\SymfonyMessengerAutoScale\BusyWorkerManager;
use Krak\SymfonyMessengerAutoScale\ProcessManager;
use Krak\SymfonyMessengerAutoScale\Tests\Feature\ProcessManagerTestOutline;

final class SymfonyProcessProcessManagerTest extends ProcessManagerTestOutline
{
    public function createProcessManager(): ProcessManager {
        $busyWorkerManager = new class implements BusyWorkerManager {
            public function markBusy(): void {}
            public function markIdle(): void {}
            public function isProcessBusy(int $pid): bool { return false; }
            public function cleanup(): void {}
        };

        return new ProcessManager\SymfonyProcessProcessManager(
            ['php', __DIR__ . '/Fixtures/run-proc.php'],
            null,
            $busyWorkerManager
        );
    }
}
