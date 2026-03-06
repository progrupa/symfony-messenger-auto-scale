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
