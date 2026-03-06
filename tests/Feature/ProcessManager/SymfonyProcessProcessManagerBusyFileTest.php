<?php

namespace Krak\SymfonyMessengerAutoScale\Tests\Feature\ProcessManager;

use Krak\SymfonyMessengerAutoScale\PidFileManager;
use Krak\SymfonyMessengerAutoScale\ProcessManager\SymfonyProcessProcessManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SymfonyProcessProcessManagerBusyFileTest extends TestCase
{
    private string $busyDir;
    private string $prefix = 'messenger-busy-';

    protected function setUp(): void
    {
        $this->busyDir = sys_get_temp_dir() . '/test-messenger-busy-' . uniqid();
        mkdir($this->busyDir, 0755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->busyDir . '/*'));
        @rmdir($this->busyDir);
    }

    private function createPidFileManager(): PidFileManager
    {
        return new PidFileManager($this->busyDir, $this->prefix);
    }

    public function testKillRefusedWhenBusyFileExists(): void
    {
        $pidFileManager = $this->createPidFileManager();
        $pm = new SymfonyProcessProcessManager(
            [PHP_BINARY, '-r', 'sleep(60);'],
            idleKillThreshold: null,
            busyWorkerManager: $pidFileManager
        );
        $proc = $pm->createProcess();
        $pid = $proc->getPid();

        file_put_contents($this->busyDir . '/' . $this->prefix . $pid, (string) time());

        $killed = $pm->killProcess($proc);
        $this->assertFalse($killed);
        $this->assertTrue($proc->isRunning());

        unlink($this->busyDir . '/' . $this->prefix . $pid);
        $proc->stop();
    }

    public function testKillAllowedWhenNoBusyFile(): void
    {
        $pm = new SymfonyProcessProcessManager(
            [PHP_BINARY, '-r', 'sleep(60);'],
            idleKillThreshold: null,
            busyWorkerManager: $this->createPidFileManager()
        );
        $proc = $pm->createProcess();

        $killed = $pm->killProcess($proc);
        $this->assertTrue($killed);
    }

    public function testBusyFileAndIdleThresholdCombined(): void
    {
        $pidFileManager = $this->createPidFileManager();
        $pm = new SymfonyProcessProcessManager(
            [PHP_BINARY, '-r', 'sleep(60);'],
            idleKillThreshold: 0,
            busyWorkerManager: $pidFileManager
        );
        $proc = $pm->createProcess();
        $pid = $proc->getPid();
        usleep(50_000);

        file_put_contents($this->busyDir . '/' . $this->prefix . $pid, (string) time());

        $killed = $pm->killProcess($proc);
        $this->assertFalse($killed, 'Should refuse kill when busy file exists, even if idle threshold passed');

        unlink($this->busyDir . '/' . $this->prefix . $pid);
        $proc->stop();
    }

    public function testForceKillIgnoresBusyFile(): void
    {
        $pidFileManager = $this->createPidFileManager();
        $pm = new SymfonyProcessProcessManager(
            [PHP_BINARY, '-r', 'sleep(60);'],
            idleKillThreshold: null,
            busyWorkerManager: $pidFileManager
        );
        $proc = $pm->createProcess();
        $pid = $proc->getPid();

        file_put_contents($this->busyDir . '/' . $this->prefix . $pid, (string) time());

        $pm->forceKill($proc);
        usleep(100_000);
        $this->assertFalse($proc->isRunning());

        @unlink($this->busyDir . '/' . $this->prefix . $pid);
    }
}
