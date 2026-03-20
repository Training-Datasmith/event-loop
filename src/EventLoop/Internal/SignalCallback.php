<?php

declare (strict_types=1);
namespace Revolt\Event_Loop\Internal;

/** @internal */
final class Signal_Callback extends Driver_Callback
{
    public function __construct(string $id, \Closure $closure, public readonly int $signal)
    {
        parent::__construct($id, $closure);
    }
}