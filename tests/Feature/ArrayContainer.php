<?php

namespace Krak\SymfonyMessengerAutoScale\Tests\Feature;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

final class ArrayContainer implements ContainerInterface
{
    private $services;

    public function __construct(array $services) {
        $this->services = $services;
    }

    public function get($id) {
        return $this->services[$id];
    }

    public function has(string $id): bool {
        return isset($this->services[$id]);
    }
}
