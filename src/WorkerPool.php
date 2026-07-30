<?php

namespace Krak\SymfonyMessengerAutoScale;

use Krak\SymfonyMessengerAutoScale\AutoScale\AutoScalerChainBuilder;
use Krak\SymfonyMessengerAutoScale\AutoScale\AutoScaleRequest;
use Krak\SymfonyMessengerAutoScale\PoolControl\WorkerPoolControl;
use Psr\Log\LogLevel;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

/**
 * Represents a collection of worker processes that are scaled/managed
 * according to the pool config and the size of the combined queue for all the receivers.
 */
final class WorkerPool
{
    const DEFAULT_HEARTBEAT_INTERVAL = 60;
    /** Seconds a pool will wait for its stragglers before force-killing them. */
    const DEFAULT_STOP_DEADLINE = 300;
    /** How often phase 2 of teardown re-checks the stragglers. */
    const STOP_POLL_INTERVAL_MICROSECONDS = 250_000;

    private string $name;
    private MessageCountAwareInterface $getMessageCount;
    private WorkerPoolControl $poolControl;
    private ProcessManager $processManager;
    private AutoScaler $autoScale;
    private EventLogger $logger;
    private PoolConfig $poolConfig;
    private array $procs;
    private $autoScaleState;
    private $timeSinceLastHeartBeat = 0;

    public function __construct(
        string                     $name,
        MessageCountAwareInterface $getMessageCount,
        WorkerPoolControl          $poolControl,
        ProcessManager             $processManager,
        EventLogger                $logger,
        PoolConfig                 $poolConfig,
        AutoScalerChainBuilder     $chainBuilder
    ) {
        $this->name = $name;
        $this->getMessageCount = $getMessageCount;
        $this->poolControl = $poolControl;
        $this->processManager = $processManager;
        $this->logger = $logger;
        $this->poolConfig = $poolConfig;
        $this->procs = [];

        $this->autoScale = $chainBuilder->build($poolConfig);
    }

    public function manage(?int $timeSinceLastCallInSeconds): void {
        $poolConfig = $this->poolControl->getPoolConfig() ?: $this->poolConfig;
        $sizeOfQueues = $this->getMessageCount->getMessageCount();

        if ($this->poolControl->shouldStop()) {
            $this->stop();
            return;
        }

        $this->beatHeart($poolConfig, $sizeOfQueues, $timeSinceLastCallInSeconds);
        $this->refreshDeadProcs();

        $resp = $this->autoScale->scale(new AutoScaleRequest($this->autoScaleState, $timeSinceLastCallInSeconds, $this->numProcs(), $sizeOfQueues));
        $this->scaleTo($resp->expectedNumProcs());
        $this->autoScaleState = $resp->state();
    }

    /**
     * Tear the pool down. Equivalent to beginStop() then awaitStopped(), kept for
     * callers that stop a single pool on its own (manage(), when the pool control
     * asks it to stop).
     *
     * Supervisor uses the two phases separately, so that EVERY pool releases its
     * idle workers before ANY pool starts waiting on a straggler.
     */
    public function stop(): void {
        $this->beginStop();
        $this->awaitStopped($this->stopDeadline());
    }

    /**
     * Phase 1 of teardown: release every worker that can be released RIGHT NOW,
     * in one sweep, without sleeping. Returns the number of stragglers left
     * behind (workers mid-message, which killProcess() refuses to kill).
     *
     * Teardown used to go through scaleTo(0), which kills at most one worker per
     * pass and sleeps 500ms between passes. A 43-worker pool therefore needed
     * ~21s to shut down even when every worker was idle, and our configured max
     * of 200 would need ~100s. That time is charged against whatever the process
     * supervisor allows (Docker's stop_grace_period), and it was being spent on
     * workers with nothing left to finish. One busy worker should cost its own
     * remaining work -- not a per-worker toll on all the others.
     */
    public function beginStop(): int {
        if ($this->poolControl->getStatus() == PoolStatus::stopped() && $this->numProcs() == 0) {
            return 0;
        }

        $this->logEvent('Stopping Pool', 'stopping');
        $this->poolControl->updateStatus(PoolStatus::stopping());

        return $this->releaseIdleProcs();
    }

    /**
     * Phase 2 of teardown: wait for beginStop()'s stragglers, then force-kill
     * anything still running once the deadline expires. A null deadline waits
     * indefinitely and never force-kills.
     *
     * $startedAt lets a caller share ONE clock across several pools so their
     * deadlines run concurrently instead of stacking. Without it, four pools each
     * willing to wait 300s add up to a 1200s worst case -- far past any realistic
     * stop_grace_period, so the process supervisor SIGKILLs the container and
     * every straggler dies mid-message regardless. Sharing the clock makes the
     * worst case the longest single pool's deadline.
     */
    public function awaitStopped(?int $deadline, ?float $startedAt = null): void {
        $startedAt ??= microtime(true);

        while ($this->numProcs() > 0 && ($deadline === null || (microtime(true) - $startedAt) < $deadline)) {
            usleep(self::STOP_POLL_INTERVAL_MICROSECONDS);
            $this->releaseIdleProcs();
        }

        if ($deadline !== null) {
            $forceKilled = 0;
            foreach ($this->procs as $index => $procRef) {
                if ($this->processManager->isProcessRunning($procRef)) {
                    $this->logEvent('Force-killing worker after stop deadline', 'force_kill', [
                        'pid' => $this->processManager->getPid($procRef),
                    ]);
                    $this->processManager->forceKill($procRef);
                    $forceKilled++;
                }
                unset($this->procs[$index]);
            }
            if ($forceKilled > 0) {
                $this->poolControl->scaleWorkers($this->numProcs());
            }
        }

        $this->logEvent('Pool stopped', 'stopped');
        $this->poolControl->updateStatus(PoolStatus::stopped());
    }

    /**
     * The pool's configured stop deadline in seconds, or null for "wait forever".
     *
     * array_key_exists rather than ??: an explicit `stop_deadline: null` means
     * "no deadline, never force-kill" (the null branch in awaitStopped), and ??
     * would silently turn that into the 300s default.
     */
    public function stopDeadline(): ?int {
        $attributes = $this->poolConfig->attributes();

        return \array_key_exists('stop_deadline', $attributes)
            ? $attributes['stop_deadline']
            : self::DEFAULT_STOP_DEADLINE;
    }

    /**
     * One sweep over every proc: drop the ones already gone, kill the ones that
     * can be killed, leave the busy ones. Returns the number still running.
     *
     * Deliberately unlike scaleDown(): no `break` after the first kill and no
     * sleep. scaleDown()'s one-at-a-time pacing keeps the autoscaler from
     * thrashing during normal operation; during teardown there is nothing to
     * thrash and every idle worker should go at once.
     *
     * scaleWorkers() is called ONCE per sweep instead of once per worker -- it
     * writes to the pool control (Redis in production), and the old path issued
     * one round-trip per killed worker.
     */
    private function releaseIdleProcs(): int {
        $released = 0;

        foreach ($this->procs as $index => $procRef) {
            if ($this->processManager->isProcessRunning($procRef) === false
                || $this->processManager->killProcess($procRef)) {
                unset($this->procs[$index]);
                $released++;
            }
        }

        if ($released > 0) {
            $this->logEvent('Scaling down worker pool', 'scale', [
                'direction' => 'down',
                'released' => $released,
            ]);
            $this->poolControl->scaleWorkers($this->numProcs());
        }

        return $this->numProcs();
    }

    private function beatHeart(PoolConfig $poolConfig, int $sizeOfQueues, ?int $timeSinceLastCallInSeconds): void {
        $heartBeatInterval = $poolConfig->attributes()['heartbeat_interval'] ?? self::DEFAULT_HEARTBEAT_INTERVAL;
        $this->timeSinceLastHeartBeat += $timeSinceLastCallInSeconds ?: 0;

        if ($this->timeSinceLastHeartBeat >= $heartBeatInterval) {
            $this->timeSinceLastHeartBeat = 0;
        }

        if ($this->timeSinceLastHeartBeat !== 0) {
            return;
        }

        $this->poolControl->scaleWorkers($this->numProcs());
        $this->poolControl->updateStatus(PoolStatus::running(), $sizeOfQueues);
        $this->logEvent('Running', 'running', ['sizeOfQueues' => $sizeOfQueues], LogLevel::INFO);
    }

    /** Scales up or down to the expected num procs */
    private function scaleTo(int $expectedNumProcs, ?int $timeout = 5): void {
        while ($expectedNumProcs > $this->numProcs()) {
            $this->scaleUp();
        }
        $now = microtime(true);
        while ($expectedNumProcs < $this->numProcs() && ($timeout === null || (microtime(true) - $now) < $timeout)) {
            $this->scaleDown();
            if ($expectedNumProcs < $this->numProcs()) {
                usleep(500_000); // avoid tight-looping when workers are busy
            }
        }
    }

    private function scaleDown() {
        foreach ($this->procs as $index => $procRef) {
            if ($this->processManager->isProcessRunning($procRef) === false || $this->processManager->killProcess($procRef)) { //  If a process was successfully killed
                $this->logEvent("Scaling down worker pool", 'scale', ['direction' => 'down']);
                unset($this->procs[$index]);    //  remove it from process list
                break;  //  we only kill one in a single pass
            }
        }
        $this->poolControl->scaleWorkers($this->numProcs());
    }

    private function scaleUp() {
        $proc = $this->processManager->createProcess();
        $this->procs[] = $proc;
        $this->logEvent("Scaling up worker pool", 'scale', ['direction' => 'up']);
        $this->poolControl->scaleWorkers($this->numProcs());
    }

    private function logEvent(string $message, string $event, array $context = [], string $level = LogLevel::NOTICE): void {
        $this->logger->logEvent(
            $message,
            'pool_'.$event,
            array_merge(
                [
                    'num_procs' => $this->numProcs(),
                    'pool' => $this->name,
                ],
                $context
            ),
            $level
        );
    }

    private function numProcs(): int {
        return count($this->procs);
    }

    /** if any of our procs got killed for some reason, we'll need to start up a replacement proc */
    private function refreshDeadProcs() {
        $this->procs = \iterator_to_array((function(array $procs) {
            foreach ($procs as $proc) {
                if ($this->processManager->isProcessRunning($proc)) {
                    yield $proc;
                    continue;
                }

                $terminationDetails = $this->processManager->getTerminationDetails($proc);
                $exitCode = $terminationDetails->getExitCode();
                $signal = $terminationDetails->getSignal();
                $level = match (true) {
                    $signal !== null && $signal !== 15 => LogLevel::ERROR,
                    $exitCode !== 0 => LogLevel::WARNING,
                    default => LogLevel::INFO,
                };
                $this->logEvent('Restarting terminated Process',
                    'restart_proc',
                    [
                        'pid' => $this->processManager->getPid($proc),
                        'exit_code' => $exitCode,
                        'signal' => $signal,
                        'output' => $terminationDetails->getStandardOutput(),
                        'error_output' => $terminationDetails->getErrorOutput(),
                    ],
                    $level
                );
                $this->processManager->killProcess($proc);
                yield $this->processManager->createProcess();
            }
        })($this->procs));
    }

}
