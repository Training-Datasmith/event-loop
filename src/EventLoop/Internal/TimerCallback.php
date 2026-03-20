<?php

declare (strict_types=1);
namespace Revolt\Event_Loop\Internal;

/** @internal */
final class Timer_Callback extends Driver_Callback
{
    public function __construct(string $id, public readonly float $interval, \Closure $callback, public float $expiration, public readonly bool $repeat = false)
    {
        parent::__construct($id, $callback);
    }
}