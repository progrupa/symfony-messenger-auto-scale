<?php

namespace Krak\SymfonyMessengerAutoScale\EventSubscriber;

use Krak\SymfonyMessengerAutoScale\BusyWorkerManager;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

class WorkerBusyGuard
{
    public function __construct(
        private readonly BusyWorkerManager $busyWorkerManager,
    ) {}

    #[AsEventListener(event: WorkerStartedEvent::class)]
    public function onWorkerStarted(): void
    {
        $this->busyWorkerManager->cleanup();
    }

    #[AsEventListener(event: WorkerMessageReceivedEvent::class)]
    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        $this->busyWorkerManager->markBusy();
    }

    #[AsEventListener(event: WorkerMessageHandledEvent::class)]
    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $this->busyWorkerManager->markIdle();
    }

    #[AsEventListener(event: WorkerMessageFailedEvent::class)]
    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $this->busyWorkerManager->markIdle();
    }

    #[AsEventListener(event: WorkerStoppedEvent::class)]
    public function onWorkerStopped(): void
    {
        $this->busyWorkerManager->markIdle();
    }
}
