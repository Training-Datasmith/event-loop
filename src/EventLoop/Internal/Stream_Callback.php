<?php

declare (strict_types=1);
namespace Revolt\Event_Loop\Internal;

/** @internal */
abstract class Stream_Callback extends Driver_Callback
{
    /**
     * @param resource $stream
     */
    public function __construct(string $id, \Closure $closure, public readonly mixed $stream)
    {
        parent::__construct($id, $closure);
    }
}