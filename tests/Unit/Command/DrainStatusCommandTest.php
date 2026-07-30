<?php

namespace Krak\SymfonyMessengerAutoScale\Tests\Unit\Command;

use Krak\SymfonyMessengerAutoScale\BusyWorkerManager;
use Krak\SymfonyMessengerAutoScale\Command\DrainStatusCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The exit code IS this command's API -- a deploy script branches on it to decide
 * whether tearing a container down would kill an in-flight message. Each code is
 * asserted directly, including the fail-closed path.
 */
final class DrainStatusCommandTest extends TestCase
{
    public function testExitsZeroWhenNoWorkerIsBusy(): void
    {
        $tester = $this->tester(new FakeBusyWorkerManager([]));

        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('safe to stop', $tester->getDisplay());
    }

    public function testExitsOneWhenAWorkerIsMidMessage(): void
    {
        $tester = $this->tester(new FakeBusyWorkerManager([4242, 4243]));

        $this->assertSame(1, $tester->execute([]));
        $this->assertStringContainsString('busy', $tester->getDisplay());
        $this->assertStringContainsString('4242', $tester->getDisplay());
    }

    /**
     * A reporting failure must never read as "safe to stop" -- the caller waits
     * instead of tearing down.
     */
    public function testExitsTwoAndReportsUnsafeWhenStateCannotBeRead(): void
    {
        $tester = $this->tester(new FakeBusyWorkerManager([], throw: new \RuntimeException('busy dir unreadable')));

        $this->assertSame(2, $tester->execute([]));
        $this->assertStringContainsString('unknown', $tester->getDisplay());
    }

    public function testJsonOutputIsMachineReadable(): void
    {
        $tester = $this->tester(new FakeBusyWorkerManager([7]));

        $this->assertSame(1, $tester->execute(['--json' => true]));

        $payload = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertFalse($payload['safe_to_stop']);
        $this->assertSame(1, $payload['busy_workers']);
        $this->assertSame([7], $payload['busy_pids']);
        $this->assertNull($payload['error']);
    }

    public function testJsonOutputOnFailureStillSaysUnsafe(): void
    {
        $tester = $this->tester(new FakeBusyWorkerManager([], throw: new \RuntimeException('boom')));

        $this->assertSame(2, $tester->execute(['--json' => true]));

        $payload = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertFalse($payload['safe_to_stop'], 'an unreadable state must never report safe_to_stop');
        $this->assertNull($payload['busy_workers']);
        $this->assertSame('boom', $payload['error']);
    }

    private function tester(BusyWorkerManager $manager): CommandTester
    {
        return new CommandTester(new DrainStatusCommand($manager));
    }
}

final class FakeBusyWorkerManager implements BusyWorkerManager
{
    /** @param int[] $busyPids */
    public function __construct(
        private readonly array $busyPids,
        private readonly ?\Throwable $throw = null,
    ) {}

    public function markBusy(): void {}

    public function markIdle(): void {}

    public function isProcessBusy(int $pid): bool
    {
        return in_array($pid, $this->busyPids, true);
    }

    public function busyPids(): array
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->busyPids;
    }

    public function cleanup(): void {}
}
