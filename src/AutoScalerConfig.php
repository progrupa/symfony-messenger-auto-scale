<?php

namespace Krak\SymfonyMessengerAutoScale;

class AutoScalerConfig
{
    public function __construct(private string $type, private array $parameters) {}

    public function getType(): string
    {
        return $this->type;
    }

    public function getParameter(string $name): mixed
    {
        return $this->parameters[$name] ?? null;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function setParameter(string $name, mixed $value): static
    {
        $this->parameters[$name] = $value;
        return $this;
    }

    public function setParameters(array $parameters): static
    {
        $this->parameters = $parameters;
        return $this;
    }
}