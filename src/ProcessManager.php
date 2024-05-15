<?php

namespace Krak\SymfonyMessengerAutoScale;

interface ProcessManager
{
    /** @return mixed a process ref */
    public function createProcess();
    public function killProcess($processRef): bool;
    public function isProcessRunning($processRef): bool;
    public function getPid($processRef): ?int;
}
