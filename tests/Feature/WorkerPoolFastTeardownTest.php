<?php

namespace Krak\SymfonyMessengerAutoScale\Tests\Feature;

use Krak\SymfonyMessengerAutoScale\AutoScale\AutoScalerChainBuilder;
use Krak\SymfonyMessengerAutoScale\AutoScale\Factory\MinMaxScalerFactory;
use Krak\SymfonyMessengerAutoScale\AutoScale\Factory\QueueNotEmptyScalerFactory;
use Krak\SymfonyMessengerAutoScale\EventLogger;
use Krak\SymfonyMessengerAutoScale\PoolConfig;
use Krak\SymfonyMessengerAutoScale\PoolControl\WorkerPoolControl;
use Krak\SymfonyMessengerAutoScale\PoolStatus;
use Krak\SymfonyMessengerAutoScale\ProcessManager;
use Krak\SymfonyMessengerAutoScale\TerminationDetails;
use Krak\SymfonyMessengerAutoScale\WorkerPool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;

/**
 * Teardown speed and the two-phase contract.
 *
 * This is the point of the change: an idle worker must never wait its turn
 * behind another worker's 500ms pacing sleep, and one straggler must not delay
 * the release of everybody else.
 */
final class WorkerPoolFastTeardownTest extends TestCase
{
    /**
     * 20 idle workers must be released in ONE sweep.
     *
     * The old path went through scaleTo(0), killing one worker per pass with a
     * 500ms sleep between passes -- 20 workers cost ~10s of pure sleeping with
     * nothing in flight. The 2s bound leaves room for slow CI while still failing
     * loudly if that pacing ever returns (it would need ~10s).
     */
    public function testIdleWorkersAreAllReleasedInOneSweep(): void
    {
        $pm = new SelectivelyBusyProcessManager();
        $pool = $this->createPool($pm, procs: 20);

        $pool->manage(null);
        $this->assertSame(20, $pm->runningCount());

        $startedAt = microtime(true);
        $stragglers = $pool->beginStop();
        $elapsed = microtime(true) - $startedAt;

        $this->assertSame(0, $stragglers, 'no worker was busy, so none should be left behind');
        $this->assertSame(0, $pm->runningCount());
        $this->assertLessThan(
            2.0,
            $elapsed,
            sprintf('releasing 20 idle workers took %.2fs -- per-worker pacing is back', $elapsed)
        );
        $this->assertSame(0, $pm->forceKillCount);
    }

    /** One busy worker must not hold the idle ones hostage. */
    public function testOneStragglerDoesNotDelayTheIdleWorkers(): void
    {
        $pm = new SelectivelyBusyProcessManager(busyProcs: [3 => 3]);
        $pool = $this->createPool($pm, procs: 10);

        $pool->manage(null);
        $this->assertSame(10, $pm->runningCount());

        $stragglers = $pool->beginStop();

        $this->assertSame(1, $stragglers, 'only the busy worker should remain after phase 1');
        $this->assertSame(1, $pm->runningCount());

        $pool->awaitStopped(10);

        $this->assertSame(0, $pm->runningCount());
        $this->assertSame(0, $pm->forceKillCount, 'the straggler finished on its own; nothing should be force-killed');
    }

    /**
     * A shared clock makes several pools' deadlines overlap instead of stack.
     * A start time already older than the deadline must expire it immediately --
     * that is what stops pool N from waiting out its own full deadline after
     * pools 1..N-1 already burned the wall clock.
     */
    public function testSharedClockExpiresADeadlineThatAlreadyElapsed(): void
    {
        $pm = new SelectivelyBusyProcessManager(busyProcs: [0 => PHP_INT_MAX, 1 => PHP_INT_MAX]);
        $pool = $this->createPool($pm, procs: 2);

        $pool->manage(null);
        $pool->beginStop();

        $sharedStart = microtime(true) - 60.0; // the shared clock started 60s ago
        $waitStartedAt = microtime(true);
        $pool->awaitStopped(5, $sharedStart);
        $waited = microtime(true) - $waitStartedAt;

        $this->assertLessThan(1.0, $waited, 'the shared deadline had already elapsed; it must not wait again');
        $this->assertSame(2, $pm->forceKillCount);
        $this->assertSame(0, $pm->runningCount());
    }

    /** An explicit `stop_deadline: null` means wait forever, not "use the default". */
    public function testExplicitNullStopDeadlineIsPreserved(): void
    {
        $pool = $this->createPool(new SelectivelyBusyProcessManager(), procs: 1, stopDeadline: null);
        $this->assertNull($pool->stopDeadline());
    }

    /** An absent `stop_deadline` falls back to the documented default. */
    public function testMissingStopDeadlineFallsBackToDefault(): void
    {
        $pool = $this->createPool(new SelectivelyBusyProcessManager(), procs: 1, omitStopDeadline: true);
        $this->assertSame(WorkerPool::DEFAULT_STOP_DEADLINE, $pool->stopDeadline());
    }

    private function createPool(
        ProcessManager $pm,
        int $procs = 2,
        ?int $stopDeadline = 300,
        bool $omitStopDeadline = false,
    ): WorkerPool {
        $messageCount = $this->createMock(MessageCountAwareInterface::class);
        $messageCount->method('getMessageCount')->willReturn(100);

        $poolControl = $this->createMock(WorkerPoolControl::class);
        $poolControl->method('getPoolConfig')->willReturn(null);
        $poolControl->method('shouldStop')->willReturn(false);
        $poolControl->method('getStatus')->willReturn(PoolStatus::running());

        $config = new PoolConfig(
            [
                ['type' => 'min-max', 'min_procs' => $procs, 'max_procs' => $procs],
                ['type' => 'queue-unhandled', 'allow_queued_per_worker' => 10],
            ],
            $omitStopDeadline ? [] : ['stop_deadline' => $stopDeadline]
        );

        $chainBuilder = new AutoScalerChainBuilder([
            new MinMaxScalerFactory(),
            new QueueNotEmptyScalerFactory(),
        ]);

        return new WorkerPool(
            'test',
            $messageCount,
            $poolControl,
            $pm,
            new EventLogger(new NullLogger()),
            $config,
            $chainBuilder
        );
    }
}

/**
 * Fake process manager where chosen procs refuse N kill attempts before going
 * idle, and every other proc is killable immediately.
 *
 * Distinct from WorkerPoolShutdownTest's CountdownProcessManager, which makes
 * ALL procs busy for the same number of iterations -- this one needs a mix, to
 * show idle workers are not delayed by a busy peer.
 */
final class SelectivelyBusyProcessManager implements ProcessManager
{
    public int $forceKillCount = 0;

    /** @var array<int, bool> proc id => running */
    private array $procs = [];
    /** @var array<int, int> proc id => remaining kill attempts to refuse */
    private array $refusalsLeft;

    /** @param array<int, int> $busyProcs proc id => how many kill attempts it refuses */
    public function __construct(private readonly array $busyProcs = [])
    {
        $this->refusalsLeft = $busyProcs;
    }

    public function createProcess()
    {
        $id = count($this->procs);
        $this->procs[$id] = true;

        return $id;
    }

    public function killProcess($processRef): bool
    {
        if (!($this->procs[$processRef] ?? false)) {
            return false;
        }

        if (($this->refusalsLeft[$processRef] ?? 0) > 0) {
            $this->refusalsLeft[$processRef]--;

            return false; // still mid-message
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
        return $processRef + 2000;
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
