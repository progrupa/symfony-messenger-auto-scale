<?php

namespace Krak\SymfonyMessengerAutoScale\Tests\Feature\Bundle;

use Krak\SymfonyMessengerAutoScale\MessengerAutoScaleBundle;
use Krak\SymfonyMessengerAutoScale\Tests\Feature\Fixtures\TestFixtureBundle;
use Krak\SymfonyMessengerRedis\MessengerRedisBundle;
use Nyholm\BundleTest\TestKernel;

trait InitsKernel
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    private function given_the_kernel_is_booted_with_config_resources(array $configResources) {
        /** @var TestKernel $kernel */
        $kernel = parent::createKernel();
        $kernel->addTestBundle(MessengerAutoScaleBundle::class);
        $kernel->addTestBundle(TestFixtureBundle::class);
        $kernel->addTestBundle(MessengerRedisBundle::class);
        foreach ($configResources as $config) {
            $kernel->addTestConfig($config);
        }
        $kernel->boot();
    }

    private function given_the_kernel_is_booted_with_messenger_and_auto_scale_config() {
        $this->given_the_kernel_is_booted_with_config_resources([
            __DIR__ . '/../Fixtures/messenger-config.yaml',
            __DIR__ . '/../Fixtures/auto-scale-config.yaml',
        ]);
    }
}
