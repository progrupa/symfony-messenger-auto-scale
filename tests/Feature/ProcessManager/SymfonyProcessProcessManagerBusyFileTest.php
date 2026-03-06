<?php

namespace Krak\SymfonyMessengerAutoScale\Tests\Feature\ProcessManager;

use Krak\SymfonyMessengerAutoScale\ProcessManager\SymfonyProcessProcessManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SymfonyProcessProcessManagerBusyFileTest extends TestCase
{
    private string $busyDir;

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

    public function testKillRefusedWhenBusyFileExists(): void
    {
        $pm = new SymfonyProcessProcessManager(
            [PHP_BINARY, '-r', 'sleep(60);'],
            idleKillThreshold: null,
            busyDir: $this->busyDir
        );
        $proc = $pm->createProcess();
        $pid = $proc->getPid();

        file_put_contents($this->busyDir . '/' . $pid, (string) time());

        $killed = $pm->killProcess($proc);
        $this->assertFalse($killed);
        $this->assertTrue($proc->isRunning());

        unlink($this->busyDir . '/' . $pid);
        $proc->stop();
    }

    public function testKillAllowedWhenNoBusyFile(): void
    {
        $pm = new SymfonyProcessProcessManager(
            [PHP_BINARY, '-r', 'sleep(60);'],
            idleKillThreshold: null,
            busyDir: $this->busyDir
        );
        $proc = $pm->createProcess();

        $killed = $pm->killProcess($proc);
        $this->assertTrue($killed);
    }

    public function testKillAllowedWhenNoBusyDir(): void
    {
        $pm = new SymfonyProcessProcessManager(
            [PHP_BINARY, '-r', 'sleep(60);'],
            idleKillThreshold: null,
            busyDir: null
        );
        $proc = $pm->createProcess();

        $killed = $pm->killProcess($proc);
        $this->assertTrue($killed);
    }

    public function testBusyFileAndIdleThresholdCombined(): void
    {
        $pm = new SymfonyProcessProcessManager(
            [PHP_BINARY, '-r', 'sleep(60);'],
            idleKillThreshold: 0,
            busyDir: $this->busyDir
        );
        $proc = $pm->createProcess();
        $pid = $proc->getPid();
        usleep(50_000);

        file_put_contents($this->busyDir . '/' . $pid, (string) time());

        $killed = $pm->killProcess($proc);
        $this->assertFalse($killed, 'Should refuse kill when busy file exists, even if idle threshold passed');

        unlink($this->busyDir . '/' . $pid);
        $proc->stop();
    }

    public function testForceKillIgnoresBusyFile(): void
    {
        $pm = new SymfonyProcessProcessManager(
            [PHP_BINARY, '-r', 'sleep(60);'],
            idleKillThreshold: null,
            busyDir: $this->busyDir
        );
        $proc = $pm->createProcess();
        $pid = $proc->getPid();

        file_put_contents($this->busyDir . '/' . $pid, (string) time());

        $pm->forceKill($proc);
        usleep(100_000);
        $this->assertFalse($proc->isRunning());

        @unlink($this->busyDir . '/' . $pid);
    }
}
