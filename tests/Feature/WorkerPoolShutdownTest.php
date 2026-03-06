<?php

namespace Krak\SymfonyMessengerAutoScale\Tests\Feature;

use Krak\SymfonyMessengerAutoScale\EventLogger;
use Krak\SymfonyMessengerAutoScale\PoolConfig;
use Psr\Log\NullLogger;
use Krak\SymfonyMessengerAutoScale\PoolControl\WorkerPoolControl;
use Krak\SymfonyMessengerAutoScale\PoolStatus;
use Krak\SymfonyMessengerAutoScale\ProcessManager;
use Krak\SymfonyMessengerAutoScale\TerminationDetails;
use Krak\SymfonyMessengerAutoScale\WorkerPool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;

final class WorkerPoolShutdownTest extends TestCase
{
    public function testStopWaitsForBusyWorkersThenSucceeds(): void
    {
        // Worker becomes idle after 1 iteration (simulating message completion)
        $pm = new CountdownProcessManager(busyForIterations: 1);
        $pool = $this->createPool($pm, stopDeadline: 10);

        // Scale up to 2 workers
        $pool->manage(null);
        $this->assertSame(2, $pm->runningCount());

        $pool->stop();

        // Both workers should be stopped — no force-kills needed
        $this->assertSame(0, $pm->runningCount());
        $this->assertSame(0, $pm->forceKillCount);
    }

    public function testStopForceKillsAfterDeadline(): void
    {
        // Worker never becomes idle
        $pm = new CountdownProcessManager(busyForIterations: PHP_INT_MAX);
        $pool = $this->createPool($pm, stopDeadline: 0); // immediate deadline

        $pool->manage(null);
        $pool->stop();

        // Workers should be force-killed
        $this->assertSame(0, $pm->runningCount());
        $this->assertTrue($pm->forceKillCount > 0, 'Workers should be force-killed after deadline');
    }

    public function testScaleDownRespectsTimeout(): void
    {
        // Worker stays busy — killProcess always returns false
        $pm = new CountdownProcessManager(busyForIterations: PHP_INT_MAX);
        $pool = $this->createPool($pm, stopDeadline: 0, minProcs: 2, maxProcs: 2);

        // Scale up to 2 workers
        $pool->manage(null);
        $this->assertSame(2, $pm->runningCount());

        // Stop with immediate deadline — workers are busy but deadline forces cleanup
        $pool->stop();

        // Workers should be force-killed after the deadline
        $this->assertSame(0, $pm->runningCount());
        $this->assertSame(2, $pm->forceKillCount);
    }

    private function createPool(
        ProcessManager $pm,
        int $stopDeadline = 300,
        int $minProcs = 2,
        int $maxProcs = 2,
    ): WorkerPool {
        $messageCount = $this->createMock(MessageCountAwareInterface::class);
        $messageCount->method('getMessageCount')->willReturn(100);

        $poolControl = $this->createMock(WorkerPoolControl::class);
        $poolControl->method('getPoolConfig')->willReturn(null);
        $poolControl->method('shouldStop')->willReturn(false);
        $poolControl->method('getStatus')->willReturn(PoolStatus::running());

        $logger = new EventLogger(new NullLogger());

        $config = new PoolConfig(
            [
                ['type' => 'min-max', 'min_procs' => $minProcs, 'max_procs' => $maxProcs],
                ['type' => 'queue-unhandled', 'allow_queued_per_worker' => 10],
            ],
            ['stop_deadline' => $stopDeadline]
        );

        return new WorkerPool('test', $messageCount, $poolControl, $pm, $logger, $config);
    }
}

/**
 * Fake process manager where workers are "busy" for N killProcess() iterations,
 * then become idle and can be killed normally.
 */
class CountdownProcessManager implements ProcessManager
{
    public int $forceKillCount = 0;
    private array $procs = [];
    private int $busyForIterations;
    private array $killAttempts = [];

    public function __construct(int $busyForIterations)
    {
        $this->busyForIterations = $busyForIterations;
    }

    public function createProcess()
    {
        $id = count($this->procs);
        $this->procs[$id] = true; // running
        $this->killAttempts[$id] = 0;
        return $id;
    }

    public function killProcess($processRef): bool
    {
        if (!($this->procs[$processRef] ?? false)) {
            return false;
        }

        $this->killAttempts[$processRef]++;
        if ($this->killAttempts[$processRef] <= $this->busyForIterations) {
            return false; // still busy
        }

        $this->procs[$processRef] = false;
        return true;
    }

    public function forceKill($processRef): void
    {
        $this->procs[$processRef] = false;
        $this->forceKillCount++;
    }

    public function isProcessRunning($processRef): bool
    {
        return $this->procs[$processRef] ?? false;
    }

    public function getPid($processRef): ?int
    {
        return $processRef + 1000;
    }

    public function getTerminationDetails($processRef): TerminationDetails
    {
        return new TerminationDetails(0, 'OK', null, '', '');
    }

    public function runningCount(): int
    {
        return count(array_filter($this->procs));
    }
}
