<?php

namespace Krak\SymfonyMessengerAutoScale\AutoScale;

use Krak\SymfonyMessengerAutoScale\AutoScaler;

/**
 * Marker interface for scalers that wrap/decorate another scaler.
 * Wrapping scalers require a subordinate scaler in the chain.
 */
interface WrappingAutoScaler extends AutoScaler
{
}
