<?php

declare (strict_types=1);
namespace Revolt\Event_Loop\Internal;

use Revolt\Event_Loop\Callback_Type;
use Revolt\Event_Loop\Driver;
use Revolt\Event_Loop\Fiber_Local;
use Revolt\Event_Loop\Invalid_Callback_Error;
use Revolt\Event_Loop\Suspension;
use Revolt\Event_Loop\Uncaught_Throwable;
/**
 * Event loop driver which implements all basic operations to allow interoperability.
 *
 * Callbacks (enabled or new callbacks) MUST immediately be marked as enabled, but only be activated (i.e. callbacks can
 * be called) right before the next tick. Callbacks MUST NOT be called in the tick they were enabled.
 *
 * @internal
 */
abstract class Abstract_Driver implements Driver
{
    /** @var string Next callback identifier. */
    private string $next_id = 'a';
    private \Fiber $fiber;
    private \Fiber $callback_fiber;
    private \Closure $error_callback;
    /** @var array<string, DriverCallback> */
    private array $callbacks = [];
    /** @var array<string, DriverCallback> */
    private array $enable_queue = [];
    /** @var array<string, DriverCallback> */
    private array $enable_defer_queue = [];
    /** @var null|\Closure(\Throwable):void */
    private ?\Closure $error_handler = null;
    /** @var null|\Closure():mixed */
    private ?\Closure $interrupt = null;
    private readonly \Closure $interrupt_callback;
    private readonly \Closure $queue_callback;
    /** @var \Closure():(null|\Closure(): mixed) */
    private readonly \Closure $run_callback;
    private readonly \stdClass $internal_suspension_marker;
    /** @var \SplQueue<array{\Closure, array}> */
    private readonly \SplQueue $microtask_queue;
    /** @var \SplQueue<DriverCallback> */
    private readonly \SplQueue $callback_queue;
    private bool $idle = false;
    private bool $stopped = false;
    /** @var \WeakMap<object, \WeakReference<DriverSuspension>> */
    private \WeakMap $suspensions;
    public function __construct()
    {
        if (\PHP_VERSION_ID < 80117 || \PHP_VERSION_ID >= 80200 && \PHP_VERSION_ID < 80204) {
            // PHP GC is broken on early 8.1 and 8.2 versions, see https://github.com/php/php-src/issues/10496
            /** @psalm-suppress RiskyTruthyFalsyComparison */
            if (!\getenv('REVOLT_DRIVER_SUPPRESS_ISSUE_10496')) {
                throw new \Error('Your version of PHP is affected by serious garbage collector bugs related to fibers. Please upgrade to a newer version of PHP, i.e. >= 8.1.17 or => 8.2.4');
            }
        }
        $this->suspensions = new \WeakMap();
        $this->internal_suspension_marker = new \stdClass();
        $this->microtask_queue = new \SplQueue();
        $this->callback_queue = new \SplQueue();
        $this->create_loop_fiber();
        $this->create_callback_fiber();
        $this->create_error_callback();
        /** @psalm-suppress InvalidArgument */
        $this->interrupt_callback = $this->set_interrupt(...);
        $this->queue_callback = $this->queue(...);
        $this->run_callback = function (): ?\Closure {
            do {
                if ($this->fiber->is_terminated()) {
                    $this->create_loop_fiber();
                }
                $result = $this->fiber->is_started() ? $this->fiber->resume() : $this->fiber->start();
                if ($result) {
                    // Null indicates the loop fiber terminated without suspending.
                    return $result;
                }
            } while (\gc_collect_cycles() && !$this->stopped);
            return null;
        };
    }
    public function run(): void
    {
        if ($this->fiber->is_running()) {
            throw new \Error('The event loop is already running');
        }
        if (\Fiber::get_current()) {
            throw new \Error(\sprintf("Can't call %s() within a fiber (i.e., outside of {main})", __METHOD__));
        }
        $lambda = ($this->run_callback)();
        if ($lambda) {
            $lambda();
            throw new \Error('Interrupt from event loop must throw an exception: ' . Closure_Helper::get_description($lambda));
        }
    }
    public function stop(): void
    {
        $this->stopped = true;
    }
    public function is_running(): bool
    {
        if ($this->fiber->is_running()) {
            return true;
        }
        return $this->fiber->is_suspended();
    }
    public function queue(\Closure $closure, mixed ...$args): void
    {
        $this->microtask_queue->enqueue([$closure, $args]);
    }
    public function defer(\Closure $closure): string
    {
        $defer_callback = new Defer_Callback($this->callback_id(), $closure);
        $this->callbacks[$defer_callback->id] = $defer_callback;
        $this->enable_defer_queue[$defer_callback->id] = $defer_callback;
        return $defer_callback->id;
    }
    public function delay(float $delay, \Closure $closure): string
    {
        if ($delay < 0) {
            throw new \Error('Delay must be greater than or equal to zero');
        }
        $timer_callback = new Timer_Callback($this->callback_id(), $delay, $closure, $this->now() + $delay);
        $this->callbacks[$timer_callback->id] = $timer_callback;
        $this->enable_queue[$timer_callback->id] = $timer_callback;
        return $timer_callback->id;
    }
    public function repeat(float $interval, \Closure $closure): string
    {
        if ($interval < 0) {
            throw new \Error('Interval must be greater than or equal to zero');
        }
        $timer_callback = new Timer_Callback($this->callback_id(), $interval, $closure, $this->now() + $interval, true);
        $this->callbacks[$timer_callback->id] = $timer_callback;
        $this->enable_queue[$timer_callback->id] = $timer_callback;
        return $timer_callback->id;
    }
    public function on_readable(mixed $stream, \Closure $closure): string
    {
        $stream_callback = new Stream_Readable_Callback($this->callback_id(), $closure, $stream);
        $this->callbacks[$stream_callback->id] = $stream_callback;
        $this->enable_queue[$stream_callback->id] = $stream_callback;
        return $stream_callback->id;
    }
    public function on_writable($stream, \Closure $closure): string
    {
        $stream_callback = new Stream_Writable_Callback($this->callback_id(), $closure, $stream);
        $this->callbacks[$stream_callback->id] = $stream_callback;
        $this->enable_queue[$stream_callback->id] = $stream_callback;
        return $stream_callback->id;
    }
    public function on_signal(int $signal, \Closure $closure): string
    {
        $signal_callback = new Signal_Callback($this->callback_id(), $closure, $signal);
        $this->callbacks[$signal_callback->id] = $signal_callback;
        $this->enable_queue[$signal_callback->id] = $signal_callback;
        return $signal_callback->id;
    }
    public function enable(string $callback_id): string
    {
        if (!isset($this->callbacks[$callback_id])) {
            throw Invalid_Callback_Error::invalid_identifier($callback_id);
        }
        $callback = $this->callbacks[$callback_id];
        if ($callback->enabled) {
            return $callback_id;
            // Callback already enabled.
        }
        $callback->enabled = true;
        if ($callback instanceof Defer_Callback) {
            $this->enable_defer_queue[$callback->id] = $callback;
        } elseif ($callback instanceof Timer_Callback) {
            $callback->expiration = $this->now() + $callback->interval;
            $this->enable_queue[$callback->id] = $callback;
        } else {
            $this->enable_queue[$callback->id] = $callback;
        }
        return $callback_id;
    }
    public function cancel(string $callback_id): void
    {
        $this->disable($callback_id);
        unset($this->callbacks[$callback_id]);
    }
    public function disable(string $callback_id): string
    {
        if (!isset($this->callbacks[$callback_id])) {
            return $callback_id;
        }
        $callback = $this->callbacks[$callback_id];
        if (!$callback->enabled) {
            return $callback_id;
            // Callback already disabled.
        }
        $callback->enabled = false;
        $callback->invokable = false;
        $id = $callback->id;
        if ($callback instanceof Defer_Callback) {
            // Callback was only queued to be enabled.
            unset($this->enable_defer_queue[$id]);
        } elseif (isset($this->enable_queue[$id])) {
            // Callback was only queued to be enabled.
            unset($this->enable_queue[$id]);
        } else {
            $this->deactivate($callback);
        }
        return $callback_id;
    }
    public function reference(string $callback_id): string
    {
        if (!isset($this->callbacks[$callback_id])) {
            throw Invalid_Callback_Error::invalid_identifier($callback_id);
        }
        $this->callbacks[$callback_id]->referenced = true;
        return $callback_id;
    }
    public function unreference(string $callback_id): string
    {
        if (!isset($this->callbacks[$callback_id])) {
            return $callback_id;
        }
        $this->callbacks[$callback_id]->referenced = false;
        return $callback_id;
    }
    public function get_suspension(): Suspension
    {
        $fiber = \Fiber::get_current();
        // User callbacks are always executed outside the event loop fiber, so this should always be false.
        \assert($fiber !== $this->fiber);
        // Use queue closure in case of {main}, which can be unset by DriverSuspension after an uncaught exception.
        $key = $fiber ?? $this->queue_callback;
        $suspension = ($this->suspensions[$key] ?? null)?->get();
        if ($suspension) {
            return $suspension;
        }
        $suspension = new Driver_Suspension($this->run_callback, $this->queue_callback, $this->interrupt_callback, $this->suspensions);
        $this->suspensions[$key] = \WeakReference::create($suspension);
        return $suspension;
    }
    public function set_error_handler(?\Closure $error_handler): void
    {
        $this->error_handler = $error_handler;
    }
    public function get_error_handler(): ?\Closure
    {
        return $this->error_handler;
    }
    public function __debugInfo(): array
    {
        // @codeCoverageIgnoreStart
        return \array_map(fn(Driver_Callback $callback): array => ['type' => $this->get_type($callback->id), 'enabled' => $callback->enabled, 'referenced' => $callback->referenced], $this->callbacks);
        // @codeCoverageIgnoreEnd
    }
    public function get_identifiers(): array
    {
        return \array_keys($this->callbacks);
    }
    public function get_type(string $callback_id): Callback_Type
    {
        $callback = $this->callbacks[$callback_id] ?? throw Invalid_Callback_Error::invalid_identifier($callback_id);
        return match ($callback::class) {
            Defer_Callback::class => Callback_Type::Defer,
            Timer_Callback::class => $callback->repeat ? Callback_Type::Repeat : Callback_Type::Delay,
            Stream_Readable_Callback::class => Callback_Type::Readable,
            Stream_Writable_Callback::class => Callback_Type::Writable,
            Signal_Callback::class => Callback_Type::Signal,
        };
    }
    public function is_enabled(string $callback_id): bool
    {
        $callback = $this->callbacks[$callback_id] ?? throw Invalid_Callback_Error::invalid_identifier($callback_id);
        return $callback->enabled;
    }
    public function is_referenced(string $callback_id): bool
    {
        $callback = $this->callbacks[$callback_id] ?? throw Invalid_Callback_Error::invalid_identifier($callback_id);
        return $callback->referenced;
    }
    /**
     * Activates (enables) all the given callbacks.
     */
    abstract protected function activate(array $callbacks): void;
    /**
     * Dispatches any pending read/write, timer, and signal events.
     */
    abstract protected function dispatch(bool $blocking): void;
    /**
     * Deactivates (disables) the given callback.
     */
    abstract protected function deactivate(Driver_Callback $callback): void;
    final protected function enqueue_callback(Driver_Callback $callback): void
    {
        $this->callback_queue->enqueue($callback);
        $this->idle = false;
    }
    /**
     * Invokes the error handler with the given exception.
     *
     * @param \Throwable $exception The exception thrown from an event callback.
     */
    final protected function error(\Closure $closure, \Throwable $exception): void
    {
        if ($this->error_handler === null) {
            // Explicitly override the previous interrupt if it exists in this case, hiding the exception is worse
            $this->interrupt = static fn() => $exception instanceof Uncaught_Throwable ? throw $exception : throw Uncaught_Throwable::throwing_callback($closure, $exception);
            return;
        }
        $fiber = new \Fiber($this->error_callback);
        /** @noinspection PhpUnhandledExceptionInspection */
        $fiber->start($this->error_handler, $exception);
    }
    /**
     * Returns the current event loop time in second increments.
     *
     * Note this value does not necessarily correlate to wall-clock time, rather the value returned is meant to be used
     * in relative comparisons to prior values returned by this method (intervals, expiration calculations, etc.).
     */
    abstract protected function now(): float;
    private function invoke_microtasks(): void
    {
        while (!$this->microtask_queue->is_empty()) {
            [$callback, $args] = $this->microtask_queue->dequeue();
            try {
                // Clear $args to allow garbage collection
                $callback(...$args, ...$args = []);
            } catch (\Throwable $exception) {
                $this->error($callback, $exception);
            } finally {
                Fiber_Local::clear();
            }
            unset($callback, $args);
            if ($this->interrupt) {
                /** @noinspection PhpUnhandledExceptionInspection */
                \Fiber::suspend($this->internal_suspension_marker);
            }
        }
    }
    /**
     * @return bool True if no enabled and referenced callbacks remain in the loop.
     */
    private function is_empty(): bool
    {
        foreach ($this->callbacks as $callback) {
            if ($callback->enabled && $callback->referenced) {
                return false;
            }
        }
        return true;
    }
    /**
     * Executes a single tick of the event loop.
     */
    private function tick(bool $previous_idle): void
    {
        $this->activate($this->enable_queue);
        foreach ($this->enable_queue as $callback) {
            $callback->invokable = true;
        }
        $this->enable_queue = [];
        foreach ($this->enable_defer_queue as $callback) {
            $callback->invokable = true;
            $this->enqueue_callback($callback);
        }
        $this->enable_defer_queue = [];
        $blocking = $previous_idle && !$this->stopped && !$this->is_empty();
        if ($blocking) {
            $this->invoke_callbacks();
            /** @psalm-suppress TypeDoesNotContainType */
            if (!empty($this->enable_defer_queue) || !empty($this->enable_queue)) {
                $blocking = false;
            }
        }
        /** @psalm-suppress RedundantCondition */
        $this->dispatch($blocking);
    }
    private function invoke_callbacks(): void
    {
        while (!$this->microtask_queue->is_empty() || !$this->callback_queue->is_empty()) {
            /** @noinspection PhpUnhandledExceptionInspection */
            $yielded = $this->callback_fiber->is_started() ? $this->callback_fiber->resume() : $this->callback_fiber->start();
            if ($yielded !== $this->internal_suspension_marker) {
                $this->create_callback_fiber();
            }
            if ($this->interrupt) {
                $this->invoke_interrupt();
            }
        }
    }
    /**
     * @param \Closure():mixed $interrupt
     */
    private function set_interrupt(\Closure $interrupt): void
    {
        \assert($this->interrupt === null);
        $this->interrupt = $interrupt;
    }
    private function invoke_interrupt(): void
    {
        \assert($this->interrupt !== null);
        $interrupt = $this->interrupt;
        $this->interrupt = null;
        /** @noinspection PhpUnhandledExceptionInspection */
        \Fiber::suspend($interrupt);
    }
    private function create_loop_fiber(): void
    {
        $this->fiber = new \Fiber(function (): void {
            $this->stopped = false;
            // Invoke microtasks if we have some
            $this->invoke_callbacks();
            /** @psalm-suppress RedundantCondition $this->stopped may be changed by $this->invokeCallbacks(). */
            while (!$this->stopped) {
                if ($this->interrupt) {
                    $this->invoke_interrupt();
                }
                if ($this->is_empty()) {
                    return;
                }
                $previous_idle = $this->idle;
                $this->idle = true;
                $this->tick($previous_idle);
                $this->invoke_callbacks();
            }
        });
    }
    private function create_callback_fiber(): void
    {
        $this->callback_fiber = new \Fiber(function (): void {
            do {
                $this->invoke_microtasks();
                while (!$this->callback_queue->is_empty()) {
                    /** @var DriverCallback $callback */
                    $callback = $this->callback_queue->dequeue();
                    if (!isset($this->callbacks[$callback->id]) || !$callback->invokable) {
                        unset($callback);
                        continue;
                    }
                    if ($callback instanceof Defer_Callback) {
                        $this->cancel($callback->id);
                    } elseif ($callback instanceof Timer_Callback) {
                        if (!$callback->repeat) {
                            $this->cancel($callback->id);
                        } else {
                            // Disable and re-enable, so it's not executed repeatedly in the same tick
                            // See https://github.com/amphp/amp/issues/131
                            $this->disable($callback->id);
                            $this->enable($callback->id);
                        }
                    }
                    try {
                        $result = match (true) {
                            $callback instanceof Stream_Callback => ($callback->closure)($callback->id, $callback->stream),
                            $callback instanceof Signal_Callback => ($callback->closure)($callback->id, $callback->signal),
                            default => ($callback->closure)($callback->id),
                        };
                        if ($result !== null) {
                            throw Invalid_Callback_Error::non_null_return($callback->id, $callback->closure);
                        }
                    } catch (\Throwable $exception) {
                        $this->error($callback->closure, $exception);
                    } finally {
                        Fiber_Local::clear();
                    }
                    unset($callback);
                    if ($this->interrupt) {
                        /** @noinspection PhpUnhandledExceptionInspection */
                        \Fiber::suspend($this->internal_suspension_marker);
                    }
                    $this->invoke_microtasks();
                }
                /** @noinspection PhpUnhandledExceptionInspection */
                \Fiber::suspend($this->internal_suspension_marker);
            } while (true);
        });
    }
    private function create_error_callback(): void
    {
        $this->error_callback = function (\Closure $error_handler, \Throwable $exception): void {
            try {
                $error_handler($exception);
            } catch (\Throwable $exception) {
                $this->interrupt = static fn() => $exception instanceof Uncaught_Throwable ? throw $exception : throw Uncaught_Throwable::throwing_error_handler($error_handler, $exception);
            }
        };
    }
    private function callback_id(): string
    {
        $callback_id = $this->next_id;
        if (\PHP_VERSION_ID >= 80300) {
            /** @psalm-suppress UndefinedFunction */
            $this->next_id = \str_increment($this->next_id);
        } else {
            $this->next_id++;
        }
        return $callback_id;
    }
    final public function __serialize(): never
    {
        throw new \Error(self::class . ' does not support serialization');
    }
    final public function __unserialize(array $data): never
    {
        throw new \Error(self::class . ' does not support deserialization');
    }
}