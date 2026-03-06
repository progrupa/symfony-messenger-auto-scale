<?php

namespace Krak\SymfonyMessengerAutoScale\Tests\Unit\EventSubscriber;

use Krak\SymfonyMessengerAutoScale\EventSubscriber\WorkerBusyGuard;
use Krak\SymfonyMessengerAutoScale\PidFileManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

final class WorkerBusyGuardTest extends TestCase
{
    private string $busyDir;
    private string $prefix = 'messenger-busy-';

    protected function setUp(): void
    {
        $this->busyDir = sys_get_temp_dir() . '/test-busy-guard-' . uniqid();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->busyDir . '/*'));
        @rmdir($this->busyDir);
    }

    private function createGuard(): WorkerBusyGuard
    {
        return new WorkerBusyGuard(new PidFileManager($this->busyDir, $this->prefix));
    }

    private function getBusyFile(): string
    {
        return $this->busyDir . '/' . $this->prefix . getmypid();
    }

    public function testOnMessageReceivedCreatesBusyFile(): void
    {
        $guard = $this->createGuard();
        $envelope = new Envelope(new \stdClass());

        $guard->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));

        $this->assertFileExists($this->getBusyFile());
    }

    public function testOnMessageReceivedCreatesDirectoryIfMissing(): void
    {
        $this->assertDirectoryDoesNotExist($this->busyDir);

        $guard = $this->createGuard();
        $envelope = new Envelope(new \stdClass());

        $guard->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));

        $this->assertDirectoryExists($this->busyDir);
        $this->assertFileExists($this->getBusyFile());
    }

    public function testOnMessageHandledRemovesBusyFile(): void
    {
        $guard = $this->createGuard();
        $envelope = new Envelope(new \stdClass());

        $guard->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $this->assertFileExists($this->getBusyFile());

        $guard->onMessageHandled(new WorkerMessageHandledEvent($envelope, 'async'));
        $this->assertFileDoesNotExist($this->getBusyFile());
    }

    public function testOnMessageFailedRemovesBusyFile(): void
    {
        $guard = $this->createGuard();
        $envelope = new Envelope(new \stdClass());

        $guard->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $this->assertFileExists($this->getBusyFile());

        $guard->onMessageFailed(new WorkerMessageFailedEvent($envelope, 'async', new \RuntimeException('fail')));
        $this->assertFileDoesNotExist($this->getBusyFile());
    }

    public function testOnWorkerStoppedRemovesBusyFile(): void
    {
        $guard = $this->createGuard();
        $envelope = new Envelope(new \stdClass());

        $guard->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $this->assertFileExists($this->getBusyFile());

        $guard->onWorkerStopped();
        $this->assertFileDoesNotExist($this->getBusyFile());
    }

    public function testRemovingNonExistentFileDoesNotError(): void
    {
        $guard = $this->createGuard();
        $envelope = new Envelope(new \stdClass());

        // No received event — file doesn't exist. Should not throw.
        $guard->onMessageHandled(new WorkerMessageHandledEvent($envelope, 'async'));
        $this->assertFileDoesNotExist($this->getBusyFile());
    }
}
