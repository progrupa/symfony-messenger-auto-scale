<?php

namespace Krak\SymfonyMessengerAutoScale\Tests\Feature;

use Krak\SymfonyMessengerAutoScale\AutoScale;
use Krak\SymfonyMessengerAutoScale\AutoScale\AutoScalerType;
use Krak\SymfonyMessengerAutoScale\AutoScaler;
use Krak\SymfonyMessengerAutoScale\AutoScalerConfig;
use PHPUnit\Framework\TestCase;

final class AutoScaleTest extends TestCase
{
    private AutoScaler $autoScale;
    /** @var AutoScale\AutoScaleResponse|null */
    private $autoScaleResp;
    private $autoScaleState;
    private $timeSinceLastCall;

    /**
     * @test
     * @dataProvider provide_for_min_max_boundaries
     */
    public function can_clip_to_min_max_boundaries(int $numProcs, int $expectedNumProcs) {
        $this->given_there_is_a_static_auto_scale_at($numProcs);
        $this->given_there_is_a_wrapping_min_max_auto_scale(1, 5);
        $this->when_auto_scale_occurs();
        $this->then_expected_num_procs_is($expectedNumProcs);
    }

    public function provide_for_min_max_boundaries() {
        yield 'below min' => [0, 1];
        yield 'ok' => [3, 3];
        yield 'above max' => [6, 5];
    }

    /**
     * @test
     * @dataProvider provide_for_queue_size_and_message_rate
     */
    public function can_determine_num_procs_on_queue_size_and_message_rate(int $queueSize, int $messageRate, int $expectedNumProcs) {
        $this->given_there_is_a_queue_size_threshold_auto_scale($messageRate);
        $this->when_auto_scale_occurs($queueSize);
        $this->then_expected_num_procs_is($expectedNumProcs);
    }

    public function provide_for_queue_size_and_message_rate() {
        yield 'queue@0,100' => [0, 100, 0];
        yield 'queue@50,100' => [50, 100, 1];
        yield 'queue@100,100' => [100, 100, 1];
        yield 'queue@101,100' => [101, 100, 2];
        yield 'queue@200,100' => [200, 100, 2];
        yield 'queue@201,100' => [201, 100, 3];
        yield 'queue@53,5' => [53, 5, 11];
        yield 'queue@57,5' => [57, 5, 12];
    }

    /**
     * @test
     * @dataProvider provide_for_queue_size_and_allowed_overflow
     */
    public function can_determine_num_procs_on_queue_size_and_allowed_overflow(int $queueSize, int $allowedOverflow, int $originalNumProcs, int $expectedNumProcs) {
        $this->given_there_is_a_queue_not_empty_auto_scale($allowedOverflow);
        $this->when_auto_scale_occurs($queueSize, $originalNumProcs);
        $this->then_expected_num_procs_is($expectedNumProcs);
    }

    public function provide_for_queue_size_and_allowed_overflow() {
        yield 'queue empty, zero processes, unchanged' => [0, 5, 0, 0];
        yield 'queue empty, should reduce' => [0, 5, 1, 0];
        yield 'queue overflowing, should increase' => [100, 5, 0, 1];
        yield 'queue within limit, unchanged' => [5, 5, 99, 99];
        yield 'queue on limit edge, unchanged' => [5, 5, 99, 99];
    }

    /**
     * @test
     * @dataProvider provide_for_queue_size_and_allowed_overflow_per_worker
     */
    public function can_determine_num_procs_on_queue_size_and_allowed_overflow_per_worker(int $queueSize, int $allowedPerProc, int $originalNumProcs, int $expectedNumProcs) {
        $this->given_there_is_a_queue_not_empty_auto_scale(0, $allowedPerProc);
        $this->when_auto_scale_occurs($queueSize, $originalNumProcs);
        $this->then_expected_num_procs_is($expectedNumProcs);
    }

    public function provide_for_queue_size_and_allowed_overflow_per_worker() {
        yield 'queue empty, zero processes, unchanged' => [0, 5, 0, 0];
        yield 'queue empty, should reduce' => [0, 5, 2, 1];
        yield 'queue overflowing, should increase' => [6, 5, 1, 2];
        yield 'queue within limit, unchanged' => [5, 5, 10, 10];
        yield 'queue on limit edge, unchanged' => [50, 5, 10, 10];
    }

    /**
     * @test
     * @dataProvider provide_for_debouncing
     *
     * @param array<array{int, int}>> $runs array of tuples where first element is queueSize and next element is current num procs
     */
    public function debounces_auto_scaling(array $runs, int $expectedProcs) {
        $this->given_there_is_a_queue_size_threshold_auto_scale(1);
        $this->given_there_is_a_wrapping_debouncing_auto_scale(2, 4);
        $this->given_the_time_since_last_call_is(1);
        $this->when_auto_scale_occurs_n_times($runs);
        $this->then_expected_num_procs_is($expectedProcs);
    }

    public function provide_for_debouncing() {
        yield 'no debouncing on first auto scale up' => [[
           [2, 0]
        ], 2];
        yield 'debounces scale up before threshold is met' => [[
            [1, 0],
            [1, 1],
            [2, 1],
            [2, 1],
        ], 1];
        yield 'debounces scale up until threshold is met' => [[
            [1, 0],
            [1, 1],
            [2, 1],
            [3, 1],
            [2, 1],
        ], 2];
        yield 'resets debounce state if expected num procs matches current procs' => [[
            [1, 0],
            [1, 1],
            [2, 1],
            [2, 1],
            [1, 1],
            [2, 1],
        ], 1];
        yield 'resets debounce state if needed scale direction changes' => [[
            [1, 0],
            [1, 1],
            [2, 1],
            [2, 1],
            [0, 1],
            [2, 1],
        ], 1];
        yield 'prevents scale up debounce if scaling from 0' => [[
            [0, 0],
            [0, 0],
            [4, 0]
        ], 4];
        yield 'resets threshold after first scale event' => [[
            [1, 0],
            [2, 1],
            [2, 1],
            [2, 1],
            [3, 2],
            [3, 2],
        ], 2];
        yield 'multiple scale up events' => [[
            [1, 0],
            [2, 1],
            [2, 1],
            [2, 1],
            [3, 2],
            [3, 2],
            [3, 2],
        ], 3];
        yield 'debounces on scale down' => [[
            [2, 0],
            [0, 2],
        ], 2];
        yield 'finishes debounces on scale down' => [[
            [2, 0],
            [0, 2],
            [0, 2],
            [0, 2],
            [0, 2],
            [0, 2],
        ], 0];
    }

    private function given_there_is_a_static_auto_scale_at(int $numProcs): void {
        $this->autoScale = new class($numProcs) implements AutoScaler {
            private $expectedNumProcs;

            public function __construct(int $expectedNumProcs) {
                $this->expectedNumProcs = $expectedNumProcs;
            }

            public function scale(AutoScale\AutoScaleRequest $autoScaleRequest): AutoScale\AutoScaleResponse {
                return new AutoScale\AutoScaleResponse($autoScaleRequest->state(), $this->expectedNumProcs);
            }
        };
    }

    private function given_there_is_a_queue_size_threshold_auto_scale(int $messageRate): void {
        $this->autoScale = new AutoScale\QueueSizeMessageRateAutoScaler(new AutoScalerConfig(AutoScalerType::QUEUE_SIZE, [AutoScale\QueueSizeMessageRateAutoScaler::PARAM_MESSAGE_RATE => $messageRate]));
    }

    private function given_there_is_a_queue_not_empty_auto_scale(int $allowedOverflow, ?int $allowedPerProc = 0): void {
        $this->autoScale = new AutoScale\QueueNotEmptyAutoScaler(
            new AutoScalerConfig(AutoScalerType::QUEUE_NOT_EMPTY, [AutoScale\QueueNotEmptyAutoScaler::PARAM_ALLOWED_OVERFLOW => $allowedOverflow, AutoScale\QueueNotEmptyAutoScaler::PARAM_ALLOWED_OVERFLOW_PER_PROC => $allowedPerProc]));
    }

    private function given_there_is_a_wrapping_min_max_auto_scale(int $min, int $max): void {
        $this->autoScale = new AutoScale\MinMaxClipAutoScaler(
            new AutoScalerConfig(AutoScalerType::MIN_MAX, [AutoScale\MinMaxClipAutoScaler::PARAM_MIN_PROCESS_COUNT => $min, AutoScale\MinMaxClipAutoScaler::PARAM_MAX_PROCESS_COUNT => $max]),
            $this->autoScale
        );
    }

    private function given_there_is_a_wrapping_debouncing_auto_scale(int $scaleUpThreshold = 0, int $scaleDownThreshold = 0): void {
        $this->autoScale = new AutoScale\DebouncingAutoScaler(
            new AutoScalerConfig(AutoScalerType::DEBOUNCE,[AutoScale\DebouncingAutoScaler::PARAM_SCALE_UP_THRESHOLD => $scaleUpThreshold, AutoScale\DebouncingAutoScaler::PARAM_SCALE_DOWN_THRESHOLD => $scaleDownThreshold]),
            $this->autoScale
        );
    }

    private function given_the_time_since_last_call_is(?int $timeSinceLastCall) {
        $this->timeSinceLastCall = $timeSinceLastCall;
    }

    private function when_auto_scale_occurs(int $queueSize = 1, int $numProcs = 1) {
        $this->autoScaleResp = $this->autoScale->scale(new AutoScale\AutoScaleRequest($this->autoScaleState, $this->timeSinceLastCall, $numProcs, $queueSize));
        $this->autoScaleState = $this->autoScaleResp->state();
    }

    private function when_auto_scale_occurs_n_times(array $args) {
        foreach ($args as [$queueSize, $numProcs]) {
            $this->when_auto_scale_occurs($queueSize, $numProcs);
        }
    }

    private function then_expected_num_procs_is(int $expected) {
        $this->assertEquals($expected, $this->autoScaleResp->expectedNumProcs());
    }
}
