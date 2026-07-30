<?php

namespace Krak\SymfonyMessengerAutoScale;

class PidFileManager implements BusyWorkerManager
{
    public function __construct(
        private readonly string $pidDir,
        private readonly string $filePrefix,
    ) {}

    public function markBusy(): void
    {
        if (!is_dir($this->pidDir)) {
            @mkdir($this->pidDir, 0755, true);
        }

        @file_put_contents($this->getFilePath(), (string) time());
    }

    public function markIdle(): void
    {
        @unlink($this->getFilePath());
    }

    public function isProcessBusy(int $pid): bool
    {
        return file_exists($this->pidDir . '/' . $this->filePrefix . $pid);
    }

    public function busyPids(): array
    {
        if (!is_dir($this->pidDir)) {
            // The directory is created lazily by markBusy(), so its absence means
            // no worker in this container has ever taken a message -- which is a
            // legitimate "nothing is busy", not an error.
            return [];
        }

        $pids = [];
        $prefixLen = strlen($this->filePrefix);

        foreach (glob($this->pidDir . '/' . $this->filePrefix . '*') ?: [] as $file) {
            $pid = (int) substr(basename($file), $prefixLen);

            // Same liveness test cleanup() uses. Pids are per PID-namespace, so
            // this is only meaningful for markers written by processes sharing
            // this one's namespace -- which is exactly the scope the caller
            // cares about (is it safe to stop THIS container).
            if ($pid > 0 && posix_kill($pid, 0)) {
                $pids[] = $pid;
            }
        }

        sort($pids);

        return $pids;
    }

    public function cleanup(): void
    {
        if (!is_dir($this->pidDir)) {
            return;
        }

        $ownPid = getmypid();
        $prefixLen = strlen($this->filePrefix);

        foreach (glob($this->pidDir . '/' . $this->filePrefix . '*') as $file) {
            $pid = (int) substr(basename($file), $prefixLen);

            if ($pid > 0 && $pid !== $ownPid && !posix_kill($pid, 0)) {
                @unlink($file);
            }
        }
    }

    private function getFilePath(): string
    {
        return $this->pidDir . '/' . $this->filePrefix . getmypid();
    }
}
