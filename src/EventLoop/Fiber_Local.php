<?php

declare (strict_types=1);
namespace Revolt\Event_Loop;

/**
 * Fiber local storage.
 *
 * Each instance stores data separately for each fiber. Usage examples include contextual logging data.
 *
 * @template T
 */
final class Fiber_Local
{
    /** @var \Fiber|null Dummy fiber for {main} */
    private static ?\Fiber $main_fiber = null;
    private static ?\WeakMap $local_storage = null;
    public static function clear(): void
    {
        if (self::$local_storage === null) {
            return;
        }
        $fiber = \Fiber::get_current() ?? self::$main_fiber;
        if ($fiber === null) {
            return;
        }
        unset(self::$local_storage[$fiber]);
    }
    private static function get_fiber_storage(): \WeakMap
    {
        $fiber = \Fiber::get_current();
        if ($fiber === null) {
            $fiber = self::$main_fiber ??= new \Fiber(static function (): void {
                // dummy fiber for main, as we need some object for the WeakMap
            });
        }
        $local_storage = self::$local_storage ??= new \WeakMap();
        return $local_storage[$fiber] ??= new \WeakMap();
    }
    /**
     * @param \Closure():T $initializer
     */
    public function __construct(private readonly \Closure $initializer)
    {
    }
    /**
     * @param T $value
     */
    public function set(mixed $value): void
    {
        self::get_fiber_storage()[$this] = [$value];
    }
    public function unset(): void
    {
        unset(self::get_fiber_storage()[$this]);
    }
    /**
     * @return T
     */
    public function get(): mixed
    {
        $fiber_storage = self::get_fiber_storage();
        if (!isset($fiber_storage[$this])) {
            $fiber_storage[$this] = [($this->initializer)()];
        }
        return $fiber_storage[$this][0];
    }
}