<?php

namespace Krak\SymfonyMessengerAutoScale\Tests\Feature;

use Krak\SymfonyMessengerAutoScale\{
    AggregatingReceiverMessageCount,
    MessengerAutoScaleBundle,
    SupervisorPoolConfig
};
use Krak\SymfonyMessengerAutoScale\Tests\Feature\Fixtures\RequiresSupervisorPoolConfigs;
use Krak\SymfonyMessengerRedis\MessengerRedisBundle;
use Nyholm\BundleTest\BaseBundleTestCase;
use Nyholm\BundleTest\CompilerPass\PublicServicePass;
use Nyholm\BundleTest\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Process\Process;

final class BundleTest extends KernelTestCase
{
    /** @var RequiresSupervisorPoolConfigs */
    private $requiresPoolConfigs;
    private $proc;

    protected function setUp(): void {
        parent::setUp();
//        $this->addCompilerPass(new PublicServicePass('/(Krak.*|krak\..*|messenger.default_serializer|messenger.receiver_locator|.*MessageBus.*)/'));
    }

    protected function tearDown(): void {
        if ($this->proc) {
            $this->proc->stop();
        }
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /** @test */
    public function supervisor_pool_config_is_built_from_sf_configuration() {
        $this->given_the_kernel_is_booted_with_config($this->messengerAndAutoScaleConfig());
        $this->when_the_requires_supervisor_pool_configs_is_created();
        $this->then_the_supervisor_pool_configs_match([
            'sales' => ['sales', 'sales_order'],
            'default' => ['catalog']
        ]);
    }

    /** @test */
    public function supervisor_pool_config_receiver_ids_are_sorted_off_of_transport_priority_option() {
        $this->given_the_kernel_is_booted_with_config($this->messengerAndAutoScaleConfigWithPriority());
        $this->when_the_requires_supervisor_pool_configs_is_created();
        $this->then_the_supervisor_pool_configs_match([
            'catalog' => ['catalog_highest', 'catalog_high', 'catalog', 'catalog_low'],
        ]);
    }

    /** @test */
    public function receiver_to_pool_mapping_is_built_from_auto_scale_config() {
        $this->given_the_kernel_is_booted_with_config($this->messengerAndAutoScaleConfig());
        $this->when_the_requires_supervisor_pool_configs_is_created();
        $this->then_the_receiver_to_pools_mapping_matches([
            'catalog' => 'default',
            'sales' => 'sales',
            'sales_order' => 'sales',
        ]);
    }

    /** @test */
    public function consuming_messages_with_a_running_supervisor() {
        $this->given_the_message_info_file_is_reset();
        $this->given_the_kernel_is_booted_with_config($this->messengerAndAutoScaleConfig());
        $this->given_the_supervisor_is_started();
        $this->when_the_messages_are_dispatched();
        $this->then_the_message_info_file_matches_the_messages_sent();
    }

    public function alerts_system() {
        // setup a queue that's overflowing
        // run the queue command
    }

    private function given_the_message_info_file_is_reset() {
        @unlink(__DIR__ . '/Fixtures/_message-info.txt');
    }

    private function given_the_kernel_is_booted_with_config(array $configFiles) {
        /** @var TestKernel $kernel */
        $kernel = parent::createKernel();
        $kernel->addTestBundle(MessengerAutoScaleBundle::class);
        $kernel->addTestBundle(Fixtures\TestFixtureBundle::class);
        $kernel->addTestBundle(MessengerRedisBundle::class);
        $kernel->addTestConfig(__DIR__ . '/Fixtures/framework-config.yaml');
        foreach ($configFiles as $configFile) {
            $kernel->addTestConfig($configFile);
        }
        $kernel->boot();
        static::$kernel = $kernel;
        static::$booted = true;
    }

    private function messengerAndAutoScaleConfig(): array {
        return [
            __DIR__ . '/Fixtures/messenger-config.yaml',
            __DIR__ . '/Fixtures/auto-scale-config.yaml',
        ];
    }

    private function messengerAndAutoScaleConfigWithPriority(): array {
        return [
            __DIR__ . '/Fixtures/messenger-config-with-priority.yaml',
            __DIR__ . '/Fixtures/auto-scale-config-with-priority.yaml',
        ];
    }

    private function given_the_supervisor_is_started() {
        $this->proc = new Process([__DIR__ . '/Fixtures/console', 'krak:auto-scale:consume']);
        $this->proc
            ->setTimeout(null)
            ->disableOutput()
            ->start();
    }

    private function waitUntil(callable $fn, int $usleepDuration = 10000) {
        $i = 0;
        while ($i < 1000) {
            if ($fn()) {
                return true;
            }
            usleep($usleepDuration);
            $i += 1;
        }

        return false;
    }

    private function when_the_requires_supervisor_pool_configs_is_created(): void {
        $this->requiresPoolConfigs = $this->getContainer()->get(RequiresSupervisorPoolConfigs::class);
    }

    private function when_the_messages_are_dispatched() {
        /** @var MessageBusInterface $bus */
        $bus = $this->getContainer()->get(MessageBusInterface::class);
        $bus->dispatch(new Fixtures\Message\CatalogMessage(1));
        $bus->dispatch(new Fixtures\Message\SalesMessage(2));

        foreach ($this->getMessageCountPools() as [$poolName, $getMessageCount]) {
            $res = $this->waitUntil(function() use ($getMessageCount) {
                return $getMessageCount->getMessageCount() === 0;
            });

            if (!$res) {
                throw new \RuntimeException('Messages never consumed...');
            }
        }
    }

    private function then_the_supervisor_pool_configs_match(array $expectedPoolNameToReceiverIds) {
        $poolNameToReceiverIds = [];
        foreach ($this->requiresPoolConfigs->poolConfigs as $poolConfig) {
            $poolNameToReceiverIds[$poolConfig->name()] = $poolConfig->receiverIds();
        }
        $this->assertEquals($expectedPoolNameToReceiverIds, $poolNameToReceiverIds);
    }

    private function getMessageCountPools(): iterable {
        foreach ($this->requiresSupervisorPoolConfigs()->poolConfigs as $poolConfig) {
            yield [$poolConfig->name(), AggregatingReceiverMessageCount::createFromReceiverIds(
                $poolConfig->receiverIds(),
                $this->getContainer()->get('messenger.receiver_locator')
            )];
        }
    }

    private function then_the_receiver_to_pools_mapping_matches(array $mapping) {
        $this->assertEquals($mapping, $this->requiresPoolConfigs->receiverToPoolMapping);
    }

    private function requiresSupervisorPoolConfigs(): RequiresSupervisorPoolConfigs {
        return $this->getContainer()->get(RequiresSupervisorPoolConfigs::class);
    }

    private function then_the_message_info_file_matches_the_messages_sent() {
        $res = array_map('trim', file(__DIR__ . '/Fixtures/_message-info.txt'));
        sort($res);
        $this->assertEquals([
            'catalog: 1',
            'sales-order: 2',
            'sales-order: 2',
            'sales: 2',
            'sales: 2',
        ], $res);
    }
}
