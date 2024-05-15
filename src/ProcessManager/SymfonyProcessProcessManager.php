<?php

namespace Krak\SymfonyMessengerAutoScale\ProcessManager;

use Krak\SymfonyMessengerAutoScale\ProcessManager;
use Symfony\Component\Process\Process;

final class SymfonyProcessProcessManager implements ProcessManager
{
    private array $cmd;
    private ?int $idleKillThreshold = null;

    public function __construct(array $cmd, ?int $idleKillThreshold = null) {
        $this->cmd = $cmd;
        $this->idleKillThreshold = $idleKillThreshold;
    }

    public function createProcess() {
        $proc = new Process($this->cmd);
        $proc->setTimeout(null)
            ->start();
        return $proc;
    }

    /**
     * @param Process $processRef
     * @return bool
     */
    public function killProcess($processRef): bool {
        if (is_null($this->idleKillThreshold) || ((microtime(true) - $processRef->getLastOutputTime()) > $this->idleKillThreshold)) {
            $processRef->stop();
            return true;
        }
        return false;
    }

    public function isProcessRunning($processRef): bool {
        /** @var Process $processRef */
        return $processRef->isRunning();
    }

    public function getPid($processRef): ?int {
        /** @var Process $processRef */
        return $processRef->getPid();
    }
}
