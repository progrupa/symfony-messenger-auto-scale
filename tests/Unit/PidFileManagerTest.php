<?php

namespace Krak\SymfonyMessengerAutoScale\Tests\Unit;

use Krak\SymfonyMessengerAutoScale\PidFileManager;
use PHPUnit\Framework\TestCase;

final class PidFileManagerTest extends TestCase
{
    private string $pidDir;
    private string $prefix = 'messenger-busy-';

    protected function setUp(): void
    {
        $this->pidDir = sys_get_temp_dir() . '/test-pid-manager-' . uniqid();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->pidDir . '/*'));
        @rmdir($this->pidDir);
    }

    public function testMarkBusyWritesPrefixedPidFile(): void
    {
        $manager = new PidFileManager($this->pidDir, $this->prefix);

        $manager->markBusy();

        $expectedFile = $this->pidDir . '/' . $this->prefix . getmypid();
        $this->assertFileExists($expectedFile);
    }

    public function testMarkBusyCreatesDirectoryIfMissing(): void
    {
        $this->assertDirectoryDoesNotExist($this->pidDir);

        $manager = new PidFileManager($this->pidDir, $this->prefix);
        $manager->markBusy();

        $this->assertDirectoryExists($this->pidDir);
    }

    public function testMarkIdleDeletesPidFile(): void
    {
        $manager = new PidFileManager($this->pidDir, $this->prefix);
        $manager->markBusy();

        $file = $this->pidDir . '/' . $this->prefix . getmypid();
        $this->assertFileExists($file);

        $manager->markIdle();
        $this->assertFileDoesNotExist($file);
    }

    public function testMarkIdleNonExistentFileDoesNotError(): void
    {
        $manager = new PidFileManager($this->pidDir, $this->prefix);
        $manager->markIdle(); // should not throw
        $this->assertTrue(true);
    }

    public function testIsProcessBusy(): void
    {
        $manager = new PidFileManager($this->pidDir, $this->prefix);
        $manager->markBusy();

        $this->assertTrue($manager->isProcessBusy(getmypid()));
        $this->assertFalse($manager->isProcessBusy(999999999));
    }

    public function testCleanupRemovesStalePrefixedFiles(): void
    {
        mkdir($this->pidDir, 0755, true);

        // Create stale files with prefix (PIDs that very likely don't exist)
        file_put_contents($this->pidDir . '/' . $this->prefix . '999999901', '1234567890');
        file_put_contents($this->pidDir . '/' . $this->prefix . '999999902', '1234567890');

        // Create own pid file (should NOT be removed)
        $ownFile = $this->pidDir . '/' . $this->prefix . getmypid();
        file_put_contents($ownFile, (string) time());

        // Create a file without prefix (should NOT be touched)
        file_put_contents($this->pidDir . '/other-file.pid', '123');

        $manager = new PidFileManager($this->pidDir, $this->prefix);
        $manager->cleanup();

        $this->assertFileDoesNotExist($this->pidDir . '/' . $this->prefix . '999999901');
        $this->assertFileDoesNotExist($this->pidDir . '/' . $this->prefix . '999999902');
        $this->assertFileExists($ownFile);
        $this->assertFileExists($this->pidDir . '/other-file.pid');
    }
}
