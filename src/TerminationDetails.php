<?php

namespace Krak\SymfonyMessengerAutoScale;

class TerminationDetails
{
    private int $exitCode;
    private string $exitReason;
    private ?int $signal;
    private string $standardOutput;
    private string $errorOutput;

    public function __construct(int $exitCode, string $exitReason, ?int $signal = null, string $standardOutput = '', string $errorOutput = '')
    {
        $this->exitCode = $exitCode;
        $this->exitReason = $exitReason;
        $this->signal = $signal;
        $this->standardOutput = $standardOutput;
        $this->errorOutput = $errorOutput;
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    public function getExitReason(): string
    {
        return $this->exitReason;
    }

    public function getSignal(): ?int
    {
        return $this->signal;
    }

    public function getStandardOutput(): string
    {
        return $this->standardOutput;
    }

    public function getErrorOutput(): string
    {
        return $this->errorOutput;
    }
}