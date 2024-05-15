<?php

namespace Krak\SymfonyMessengerAutoScale\Command;

use Krak\SymfonyMessengerAutoScale\Supervisor;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ShutdownCommand extends Command
{
    private $cache;

    public function __construct(CacheItemPoolInterface $appCache)
    {
        parent::__construct();

        $this->cache = $appCache;
    }


    protected function configure() {
        $this->setName('krak:auto-scale:shutdown')
            ->setDescription('Request a shutdown of the worker pool supervisor');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->cache->save(
            $this->cache->getItem(Supervisor::SHUTDOWN_CACHE_KEY)->set(microtime(true))
        );
        $output->writeln("<info>Supervisor shutdown scheduled</info>");

        return Command::SUCCESS;
    }
}