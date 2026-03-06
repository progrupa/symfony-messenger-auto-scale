<?php

namespace Krak\SymfonyMessengerAutoScale;

interface ProcessManager
{
    /** @return mixed a process ref */
    public function createProcess();
    public function killProcess($processRef): bool;
    /** Force-kill the process unconditionally. Used after shutdown deadline expires. */
    public function forceKill($processRef): void;
    public function isProcessRunning($processRef): bool;
    public function getPid($processRef): ?int;
    public function getTerminationDetails($processRef): TerminationDetails;
}
