<?php

declare (strict_types=1);
namespace Revolt\Event_Loop;

/**
 * MUST be thrown if a feature is not supported by the system.
 *
 * This might happen if ext-pcntl is missing and the loop driver doesn't support another way to dispatch signals.
 */
final class Unsupported_Feature_Exception extends \Exception
{
}