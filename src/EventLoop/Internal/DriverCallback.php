<?php

declare (strict_types=1);
namespace Revolt\Event_Loop\Internal;

/**
 * @internal
 */
abstract class Driver_Callback
{
    public bool $invokable = false;
    public bool $enabled = true;
    public bool $referenced = true;
    public function __construct(public readonly string $id, public readonly \Closure $closure)
    {
    }
    public function __get(string $property): never
    {
        throw new \Error("Unknown property '{$property}'");
    }
    public function __set(string $property, mixed $value): never
    {
        throw new \Error("Unknown property '{$property}'");
    }
}