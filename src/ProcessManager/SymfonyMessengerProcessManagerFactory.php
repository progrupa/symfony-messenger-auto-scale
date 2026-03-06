<?php

namespace Krak\SymfonyMessengerAutoScale\ProcessManager;

use Krak\SymfonyMessengerAutoScale\BusyWorkerManager;
use Krak\SymfonyMessengerAutoScale\ProcessManager;
use Krak\SymfonyMessengerAutoScale\ProcessManagerFactory;
use Krak\SymfonyMessengerAutoScale\SupervisorPoolConfig;

/**
 * Create symfony process process manager with the symfony messenger:consume defaults.
 */
final class SymfonyMessengerProcessManagerFactory implements ProcessManagerFactory
{
    private $pathToConsole;
    private $command;
    private $defaultOpts;
    private BusyWorkerManager $busyWorkerManager;

    public function __construct(string $pathToConsole, BusyWorkerManager $busyWorkerManager, string $command = 'messenger:consume', array $defaultOpts = []) {
        $this->pathToConsole = $pathToConsole;
        $this->busyWorkerManager = $busyWorkerManager;
        $this->command = $command;
        $this->defaultOpts = $defaultOpts;
    }

    public function createFromSupervisorPoolConfig(SupervisorPoolConfig $config): ProcessManager {
        $command = $config->poolConfig()->attributes()['worker_command'] ?? $this->command;
        $options = $config->poolConfig()->attributes()['worker_command_options'] ?? $this->defaultOpts;
        return new SymfonyProcessProcessManager(
            array_merge([PHP_BINARY, $this->pathToConsole, $command], $options, $config->receiverIds()),
            $config->poolConfig()->attributes()['idle_kill_threshold'] ?? null,
            $this->busyWorkerManager
        );
    }
}
