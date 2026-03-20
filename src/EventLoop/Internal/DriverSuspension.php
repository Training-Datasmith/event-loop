<?php

/** @noinspection PhpPropertyOnlyWrittenInspection */
declare (strict_types=1);
namespace Revolt\Event_Loop\Internal;

use Revolt\Event_Loop\Suspension;
/**
 * @internal
 *
 * @template T
 * @implements Suspension<T>
 */
final class Driver_Suspension implements Suspension
{
    /** @var \WeakReference<\Fiber>|null */
    private readonly ?\WeakReference $fiber_ref;
    private ?\Error $error = null;
    private bool $pending = false;
    private bool $dead_main = false;
    /**
     * @param \WeakMap<object, \WeakReference<DriverSuspension>> $suspensions
     */
    public function __construct(private readonly \Closure $run, private readonly \Closure $queue, private readonly \Closure $interrupt, private readonly \WeakMap $suspensions)
    {
        $fiber = \Fiber::get_current();
        $this->fiber_ref = $fiber ? \WeakReference::create($fiber) : null;
    }
    public function resume(mixed $value = null): void
    {
        // Ignore spurious resumes to old dead {main} suspension
        if ($this->dead_main) {
            return;
        }
        if (!$this->pending) {
            throw $this->error ?? new \Error('Must call suspend() before calling resume()');
        }
        $this->pending = false;
        /** @var \Fiber|null $fiber */
        $fiber = $this->fiber_ref?->get();
        if ($fiber) {
            ($this->queue)(static function () use ($fiber, $value): void {
                // The fiber may be destroyed with suspension as part of the GC cycle collector.
                if (!$fiber->is_terminated()) {
                    $fiber->resume($value);
                }
            });
        } else {
            // Suspend event loop fiber to {main}.
            ($this->interrupt)(static fn(): mixed => $value);
        }
    }
    public function suspend(): mixed
    {
        // Throw exception when trying to use old dead {main} suspension
        if ($this->dead_main) {
            throw new \Error('Suspension cannot be suspended after an uncaught exception is thrown from the event loop');
        }
        if ($this->pending) {
            throw new \Error('Must call resume() or throw() before calling suspend() again');
        }
        $fiber = $this->fiber_ref?->get();
        if ($fiber !== \Fiber::get_current()) {
            throw new \Error('Must not call suspend() from another fiber');
        }
        $this->pending = true;
        $this->error = null;
        // Awaiting from within a fiber.
        if ($fiber) {
            try {
                $value = \Fiber::suspend();
            } catch (\Fiber_Error $error) {
                $this->pending = false;
                $this->error = $error;
                throw $error;
            }
            // Setting $this->suspendedFiber = null in finally will set the fiber to null if a fiber is destroyed
            // as part of a cycle collection, causing an error if the suspension is subsequently resumed.
            return $value;
        }
        // Awaiting from {main}.
        $result = ($this->run)();
        /** @psalm-suppress RedundantCondition $this->pending should be changed when resumed. */
        if ($this->pending) {
            // This is now a dead {main} suspension.
            $this->dead_main = true;
            // Unset suspension for {main} using queue closure.
            unset($this->suspensions[$this->queue]);
            $result && $result();
            // Unwrap any uncaught exceptions from the event loop
            \gc_collect_cycles();
            // Collect any circular references before dumping pending suspensions.
            $info = '';
            foreach ($this->suspensions as $suspension_ref) {
                if ($suspension = $suspension_ref->get()) {
                    \assert($suspension instanceof self);
                    $fiber = $suspension->fiber_ref?->get();
                    if ($fiber === null) {
                        continue;
                    }
                    $reflection_fiber = new \Reflection_Fiber($fiber);
                    $info .= "\n\n" . $this->format_stacktrace($reflection_fiber->get_trace(\DEBUG_BACKTRACE_IGNORE_ARGS));
                }
            }
            throw new \Error('Event loop terminated without resuming the current suspension (the cause is either a fiber deadlock, or an incorrectly unreferenced/canceled watcher):' . $info);
        }
        return $result();
    }
    public function throw(\Throwable $throwable): void
    {
        // Ignore spurious resumes to old dead {main} suspension
        if ($this->dead_main) {
            return;
        }
        if (!$this->pending) {
            throw $this->error ?? new \Error('Must call suspend() before calling throw()');
        }
        $this->pending = false;
        /** @var \Fiber|null $fiber */
        $fiber = $this->fiber_ref?->get();
        if ($fiber) {
            ($this->queue)(static function () use ($fiber, $throwable): void {
                // The fiber may be destroyed with suspension as part of the GC cycle collector.
                if (!$fiber->is_terminated()) {
                    $fiber->throw($throwable);
                }
            });
        } else {
            // Suspend event loop fiber to {main}.
            ($this->interrupt)(static fn() => throw $throwable);
        }
    }
    private function format_stacktrace(array $trace): string
    {
        return \implode("\n", \array_map(static function (array $e, int|string $i): string {
            $line = "#{$i} ";
            if (isset($e['file'])) {
                $line .= "{$e['file']}:{$e['line']} ";
            }
            if (isset($e['class'], $e['type'])) {
                $line .= $e['class'] . $e['type'];
            }
            return $line . $e['function'] . '()';
        }, $trace, \array_keys($trace)));
    }
}