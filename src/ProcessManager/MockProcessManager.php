<?php

namespace Krak\SymfonyMessengerAutoScale\ProcessManager;

use Krak\SymfonyMessengerAutoScale\ProcessManager;
use Krak\SymfonyMessengerAutoScale\TerminationDetails;

final class MockProcessManager implements ProcessManager
{
    private $idCounter = 1;
    private $procsById = [];

    public function createProcess() {
        $procId = $this->idCounter;
        $this->idCounter += 1;
        $proc = ['isRunning' => true, 'id' => $procId];
        $this->procsById[$procId] = $proc;
        return $procId;
    }

    public function killProcess($processRef): bool {
        unset($this->procsById[$processRef]);
        return true;
    }

    public function isProcessRunning($processRef): bool {
        return $this->procsById[$processRef]['isRunning'];
    }

    public function getPid($processRef): ?int {
        return $processRef;
    }

    public function getTerminationDetails($processRef): TerminationDetails {
        return new TerminationDetails(
            0,
            null,
            'Mock process terminated',
            ''
        );
    }

    public function stopProcess(int $processId): void {
        $this->procsById[$processId]['isRunning'] = false;
    }

    public function getProcs(): array {
        return array_values($this->procsById);
    }
}
