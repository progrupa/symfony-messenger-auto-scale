<?php

namespace Krak\SymfonyMessengerAutoScale;

use Krak\SymfonyMessengerAutoScale\AutoScale\AutoScaleRequest;
use Krak\SymfonyMessengerAutoScale\AutoScale\AutoScalerType;
use Krak\SymfonyMessengerAutoScale\AutoScale\DebouncingAutoScaler;
use Krak\SymfonyMessengerAutoScale\AutoScale\MinMaxClipAutoScaler;
use Krak\SymfonyMessengerAutoScale\AutoScale\QueueNotEmptyAutoScaler;
use Krak\SymfonyMessengerAutoScale\AutoScale\QueueSizeMessageRateAutoScaler;
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
        PoolConfig                 $poolConfig
    ) {
        $this->name = $name;
        $this->getMessageCount = $getMessageCount;
        $this->poolControl = $poolControl;
        $this->processManager = $processManager;
        $this->logger = $logger;
        $this->poolConfig = $poolConfig;
        $this->procs = [];

        $this->autoScale = $this->buildAutoScale($poolConfig);
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

        $resp = $this->autoScale->scale(new AutoScaleRequest($this->autoScaleState, $timeSinceLastCallInSeconds, $this->numProcs(), $sizeOfQueues, $poolConfig));
        $this->scaleTo($resp->expectedNumProcs());
        $this->autoScaleState = $resp->state();
    }

    public function stop(): void {
        if ($this->poolControl->getStatus() == PoolStatus::stopped() && $this->numProcs() == 0) {
            return;
        }

        $this->logEvent('Stopping Pool', 'stopping');
        $this->poolControl->updateStatus(PoolStatus::stopping());

        $this->scaleTo(0, false);

        $this->logEvent('Pool stopped', 'stopped');
        $this->poolControl->updateStatus(PoolStatus::stopped());
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
    private function scaleTo(int $expectedNumProcs, bool $timeout = true): void {
        while ($expectedNumProcs > $this->numProcs()) {
            $this->scaleUp();
        }
        $now = microtime(true);
        //  Try scaling down for 5 seconds, workers might still be busy
        while ($expectedNumProcs < $this->numProcs() && ($timeout == false || ((microtime(true) - $now) < 5))) {
            $this->scaleDown();
        }
    }

    private function scaleDown() {
        foreach ($this->procs as $index => $procRef) {
            if ($this->processManager->killProcess($procRef)) { //  If a process was successfully killed
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

                $this->logEvent('Restarting Process', 'restart_proc', ['pid' => $this->processManager->getPid($proc)], LogLevel::WARNING);
                $this->processManager->killProcess($proc);
                yield $this->processManager->createProcess();
            }
        })($this->procs));
    }

    private function buildAutoScale(PoolConfig $config)
    {
        foreach (array_reverse($config->getScalerConfigs()) as $scalerConfig) {
            $scaler = match ($scalerConfig->getType()) {
                AutoScalerType::QUEUE_SIZE => new QueueSizeMessageRateAutoScaler($scalerConfig),
                AutoScalerType::QUEUE_NOT_EMPTY => new QueueNotEmptyAutoScaler($scalerConfig),
                AutoScalerType::MIN_MAX => new MinMaxClipAutoScaler($scalerConfig, $scaler),
                AutoScalerType::DEBOUNCE => new DebouncingAutoScaler($scalerConfig, $scaler),
            };
        }

        return $scaler;
    }
}
