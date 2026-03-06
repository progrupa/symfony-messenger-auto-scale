<?php

namespace Krak\SymfonyMessengerAutoScale\Tests\Feature\Bundle;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReceiverValidationTest extends KernelTestCase
{
    use InitsKernel;

    private ?\Throwable $exception = null;

    /** @test */
    public function throws_exception_for_unknown_receiver() {
        try {
            $this->given_the_kernel_is_booted_with_config_resources([
                __DIR__ . '/../Fixtures/messenger-config.yaml',
                __DIR__ . '/../Fixtures/auto-scale-config-with-unknown-receiver.yaml',
            ]);
        } catch (\Throwable $e) {
            $this->exception = $e;
        }

        $this->assertInstanceOf(\LogicException::class, $this->exception);
        $this->assertStringContainsString('not defined in framework.messenger.transports', $this->exception->getMessage());
        $this->assertStringContainsString('nonexistent', $this->exception->getMessage());
    }

    /** @test */
    public function throws_exception_for_duplicate_receiver_across_pools() {
        try {
            $this->given_the_kernel_is_booted_with_config_resources([
                __DIR__ . '/../Fixtures/messenger-config.yaml',
                __DIR__ . '/../Fixtures/auto-scale-config-with-duplicate-receiver.yaml',
            ]);
        } catch (\Throwable $e) {
            $this->exception = $e;
        }

        $this->assertInstanceOf(\LogicException::class, $this->exception);
        $this->assertStringContainsString('already claimed by pool', $this->exception->getMessage());
    }

    /** @test */
    public function throws_exception_for_unknown_scaler_type() {
        try {
            $this->given_the_kernel_is_booted_with_config_resources([
                __DIR__ . '/../Fixtures/messenger-config.yaml',
                __DIR__ . '/../Fixtures/auto-scale-config-with-unknown-scaler.yaml',
            ]);
        } catch (\Throwable $e) {
            $this->exception = $e;
        }

        $this->assertInstanceOf(\LogicException::class, $this->exception);
        $this->assertStringContainsString('unknown scaler type', $this->exception->getMessage());
        $this->assertStringContainsString('nonexistent-scaler', $this->exception->getMessage());
    }

    /** @test */
    public function throws_exception_for_scaler_chain_without_base_scaler() {
        try {
            $this->given_the_kernel_is_booted_with_config_resources([
                __DIR__ . '/../Fixtures/messenger-config.yaml',
                __DIR__ . '/../Fixtures/auto-scale-config-with-no-base-scaler.yaml',
            ]);
        } catch (\Throwable $e) {
            $this->exception = $e;
        }

        $this->assertInstanceOf(\LogicException::class, $this->exception);
        $this->assertStringContainsString('no base scaler', $this->exception->getMessage());
    }
}
