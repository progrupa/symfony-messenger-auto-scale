<?php

namespace Krak\SymfonyMessengerAutoScale\AutoScale;

use Krak\SymfonyMessengerAutoScale\AutoScaler;
use Krak\SymfonyMessengerAutoScale\AutoScalerConfig;

/**
 * Factory for creating auto-scaler instances.
 *
 * Implement this interface and the bundle will autoconfigure it.
 * Use in pool config via: scalers: [{type: 'your-type', ...}]
 */
interface AutoScalerFactory
{
    /** The type string used in YAML config (e.g., 'queue-size', 'min-max'). */
    public static function getType(): string;

    /** Whether this factory creates a wrapping scaler that requires a subordinate. */
    public static function isWrapping(): bool;

    /** Create the auto-scaler instance. $subordinate is the next scaler in the chain (null for base scalers). */
    public function create(AutoScalerConfig $config, ?AutoScaler $subordinate): AutoScaler;
}
