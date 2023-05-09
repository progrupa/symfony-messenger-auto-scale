<?php

namespace Krak\SymfonyMessengerAutoScale\PoolControl;

use Krak\SymfonyMessengerAutoScale\AutoScalerConfig;
use Krak\SymfonyMessengerAutoScale\PoolConfig;
use Krak\SymfonyMessengerAutoScale\PoolControl;

/**
 * Access to the PoolControl from an external actor who can
 * monitor and request changes to the state of the pool
 */
interface ActorPoolControl extends PoolControl
{
    public function restart(): void;
    public function pause(): void;
    public function resume(): void;
}
