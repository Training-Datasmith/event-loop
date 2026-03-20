<?php

declare (strict_types=1);
namespace Revolt\Event_Loop;

// @codeCoverageIgnoreStart
use Revolt\Event_Loop\Driver\Ev_Driver;
use Revolt\Event_Loop\Driver\Event_Driver;
use Revolt\Event_Loop\Driver\Stream_Select_Driver;
use Revolt\Event_Loop\Driver\Tracing_Driver;
use Revolt\Event_Loop\Driver\Uv_Driver;
final class Driver_Factory
{
    /**
     * Creates a new loop instance and chooses the best available driver.
     *
     *
     * @throws \Error If an invalid class has been specified via REVOLT_LOOP_DRIVER
     */
    public function create(): Driver
    {
        $driver = (function (): \Revolt\Event_Loop\Driver|\Revolt\Event_Loop\Driver\Uv_Driver|\Revolt\Event_Loop\Driver\Ev_Driver|\Revolt\Event_Loop\Driver\Event_Driver|\Revolt\Event_Loop\Driver\Stream_Select_Driver {
            if ($driver = $this->create_driver_from_env()) {
                return $driver;
            }
            if (Uv_Driver::is_supported()) {
                return new Uv_Driver();
            }
            if (Ev_Driver::is_supported()) {
                return new Ev_Driver();
            }
            if (Event_Driver::is_supported()) {
                return new Event_Driver();
            }
            return new Stream_Select_Driver();
        })();
        /** @psalm-suppress RiskyTruthyFalsyComparison */
        if (\getenv('REVOLT_DRIVER_DEBUG_TRACE')) {
            return new Tracing_Driver($driver);
        }
        return $driver;
    }
    private function create_driver_from_env(): ?Driver
    {
        $driver = \getenv('REVOLT_DRIVER');
        /** @psalm-suppress RiskyTruthyFalsyComparison */
        if (!$driver) {
            return null;
        }
        if (!\class_exists($driver)) {
            throw new \Error(\sprintf("Driver '%s' does not exist.", $driver));
        }
        if (!\is_subclass_of($driver, Driver::class)) {
            throw new \Error(\sprintf("Driver '%s' is not a subclass of '%s'.", $driver, Driver::class));
        }
        return new $driver();
    }
}
// @codeCoverageIgnoreEnd