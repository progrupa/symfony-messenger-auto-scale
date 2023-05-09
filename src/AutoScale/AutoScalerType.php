<?php

namespace Krak\SymfonyMessengerAutoScale\AutoScale;

class AutoScalerType
{
    const MIN_MAX = 'min-max';
    const DEBOUNCE = 'debounce';
    const QUEUE_SIZE = 'queue-size';
    const QUEUE_NOT_EMPTY = 'queue-unhandled';
}