<?php

declare(strict_types=1);

namespace Revolt\EventLoop\Internal;

/**
 * @internal
 */
abstract class DriverCallback
{
    public bool $invokable = false;

    public bool $enabled = true;

    public bool $referenced = true;

    public function __construct(
        public readonly string $id,
        public readonly \Closure $closure
    ) {
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
