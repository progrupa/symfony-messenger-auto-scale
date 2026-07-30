<?php

namespace Krak\SymfonyMessengerAutoScale\Command;

use Krak\SymfonyMessengerAutoScale\BusyWorkerManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Answers one question, machine-readably: is it safe to stop this container?
 *
 * Deliberately NOT the same thing as krak:auto-scale:pool:status. That command
 * reads the pool control (Redis in production), whose keys are scoped by POOL
 * NAME only -- so every container consuming the same pools shares them. During a
 * blue-green deploy both colours write the same keys, and the value you read back
 * may describe the other colour's supervisor. That is fine for an operator
 * eyeballing throughput and useless for deciding whether to tear a container down.
 *
 * This command reads only process-local truth: the busy-marker directory, which
 * every worker in THIS container writes to as it takes and finishes a message.
 * No shared state, nothing another container can influence.
 *
 * Exit codes are the API; the JSON is for logs and humans:
 *   0  safe to stop  -- no worker in this container is mid-message
 *   1  still busy     -- at least one worker is mid-message
 *   2  unknown        -- state could not be determined; treat as busy
 *
 * Fail-closed on purpose: a caller that cannot read the state must wait, not
 * tear down. Callers should branch on the exit code and only parse stdout for
 * detail.
 */
final class DrainStatusCommand extends Command
{
    public function __construct(private readonly BusyWorkerManager $busyWorkerManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('krak:auto-scale:drain-status')
            ->setDescription('Report whether any worker in THIS container is mid-message (exit 0 safe to stop, 1 busy, 2 unknown)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit JSON on stdout instead of a one-line summary');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $busyPids = $this->busyWorkerManager->busyPids();
        } catch (\Throwable $e) {
            // Never let a reporting failure read as "safe to stop".
            $this->emit($output, (bool) $input->getOption('json'), [
                'safe_to_stop' => false,
                'busy_workers' => null,
                'busy_pids' => [],
                'error' => $e->getMessage(),
            ]);

            return 2;
        }

        $safeToStop = $busyPids === [];

        $this->emit($output, (bool) $input->getOption('json'), [
            'safe_to_stop' => $safeToStop,
            'busy_workers' => \count($busyPids),
            'busy_pids' => $busyPids,
            'error' => null,
        ]);

        return $safeToStop ? 0 : 1;
    }

    /** @param array<string, mixed> $payload */
    private function emit(OutputInterface $output, bool $asJson, array $payload): void
    {
        if ($asJson) {
            $output->writeln(json_encode($payload, JSON_THROW_ON_ERROR));

            return;
        }

        if ($payload['error'] !== null) {
            $output->writeln(sprintf('<error>drain-status: unknown (%s)</error>', $payload['error']));

            return;
        }

        if ($payload['safe_to_stop']) {
            $output->writeln('<info>drain-status: safe to stop -- no worker is mid-message</info>');

            return;
        }

        $output->writeln(sprintf(
            '<comment>drain-status: busy -- %d worker(s) mid-message (pids: %s)</comment>',
            $payload['busy_workers'],
            implode(', ', $payload['busy_pids']),
        ));
    }
}
