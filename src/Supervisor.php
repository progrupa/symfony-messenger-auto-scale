<?php

namespace Krak\SymfonyMessengerAutoScale;

use Krak\SymfonyMessengerAutoScale\AutoScale\DebouncingAutoScaler;
use Krak\SymfonyMessengerAutoScale\AutoScale\MinMaxClipAutoScaler;
use Krak\SymfonyMessengerAutoScale\AutoScale\QueueSizeMessageRateAutoScaler;
use Psr\Log\{LoggerInterface, NullLogger};
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;

/**
 * Entrypoint for managing worker pools.
 */
final class Supervisor
{
    const SLEEP_TIME = 1;
    const SHUTDOWN_CACHE_KEY = 'krak.supervisor.shutdown_timestamp';

    private $processManagerFactory;
    private $poolControlFactory;
    private $receiversById;
    private $appCache;
    private $supervisorPoolConfigs;
    private $logger;
    private $shouldShutdown = false;
    private $supervisorStartedAt;

    /** @param SupervisorPoolConfig[] $supervisorPoolConfigs */
    public function __construct(
        ProcessManagerFactory  $processManagerFactory,
        PoolControlFactory     $poolControlFactory,
        ContainerInterface     $receiversById,
        CacheItemPoolInterface $appCache,
        array                  $supervisorPoolConfigs,
        ?LoggerInterface       $logger = null
    ) {
        $this->processManagerFactory = $processManagerFactory;
        $this->poolControlFactory = $poolControlFactory;
        $this->receiversById = $receiversById;
        $this->appCache = $appCache;
        $this->supervisorPoolConfigs = $this->assertUniquePoolNames($supervisorPoolConfigs);
        $this->logger = new EventLogger($logger ?: new NullLogger());

        $this->supervisorStartedAt = microtime(true);
    }

    public function run(): void {
        $this->registerPcntlSignalHandlers();

        $workerPools = $this->createWorkersFromPoolConfigs($this->supervisorPoolConfigs);
        $timeSinceLastCall = null;
        while (!$this->shouldShutdown) {
            foreach ($workerPools as $pool) {
                $pool->manage($timeSinceLastCall);
            }
            sleep(self::SLEEP_TIME);
            $timeSinceLastCall = self::SLEEP_TIME;
            $this->checkShutdown();
        }

        foreach ($workerPools as $pool) {
            $pool->stop();
        }
    }

    private function checkShutdown(): void
    {
        $shutdownTime = $this->appCache->getItem(self::SHUTDOWN_CACHE_KEY);
        if ($shutdownTime->isHit() && $shutdownTime->get() > $this->supervisorStartedAt) {
            $this->shouldShutdown = true;
        }
    }

    /**
     * @param SupervisorPoolConfig[] $supervisorPoolConfigs
     * @return SupervisorPoolConfig[]
     */
    private function assertUniquePoolNames(array $supervisorPoolConfigs): array {
        $poolNames = array_map(function(SupervisorPoolConfig $config) {
            return $config->name();
        }, $supervisorPoolConfigs);

        if (\count($poolNames) === count(\array_unique($poolNames))) {
            return $supervisorPoolConfigs;
        }

        throw new \RuntimeException('The pool names must be unique across all pool configurations.');
    }

    /** @return WorkerPool[] */
    private function createWorkersFromPoolConfigs(array $supervisorPoolConfigs): array {
        return array_map(function(SupervisorPoolConfig $config) {
            return new WorkerPool(
                $config->name(),
                AggregatingReceiverMessageCount::createFromReceiverIds($config->receiverIds(), $this->receiversById),
                $this->poolControlFactory->createForWorker($config->name()),
                $this->processManagerFactory->createFromSupervisorPoolConfig($config),
                $this->logger,
                $config->poolConfig()
            );
        }, $supervisorPoolConfigs);
    }

    private function registerPcntlSignalHandlers(): void {
        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, function() {
                $this->shouldShutdown = true;
            });
        }
    }

}
