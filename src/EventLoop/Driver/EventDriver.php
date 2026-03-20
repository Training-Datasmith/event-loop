<?php

declare (strict_types=1);
/** @noinspection PhpComposerExtensionStubsInspection */
namespace Revolt\Event_Loop\Driver;

use Revolt\Event_Loop\Internal\Abstract_Driver;
use Revolt\Event_Loop\Internal\Driver_Callback;
use Revolt\Event_Loop\Internal\Signal_Callback;
use Revolt\Event_Loop\Internal\Stream_Callback;
use Revolt\Event_Loop\Internal\Stream_Readable_Callback;
use Revolt\Event_Loop\Internal\Stream_Writable_Callback;
use Revolt\Event_Loop\Internal\Timer_Callback;
final class Event_Driver extends Abstract_Driver
{
    /** @var array<string, \Event>|null */
    private static ?array $active_signals = null;
    public static function is_supported(): bool
    {
        return \extension_loaded('event');
    }
    private \Event_Base $handle;
    /** @var array<string, \Event> */
    private array $events = [];
    private readonly \Closure $io_callback;
    private readonly \Closure $timer_callback;
    private readonly \Closure $signal_callback;
    /** @var array<string, \Event> */
    private array $signals = [];
    public function __construct()
    {
        parent::__construct();
        /** @psalm-suppress TooFewArguments https://github.com/JetBrains/phpstorm-stubs/pull/763 */
        $this->handle = new \Event_Base();
        if (self::$active_signals === null) {
            self::$active_signals =& $this->signals;
        }
        $this->io_callback = function ($resource, $what, Stream_Callback $callback): void {
            $this->enqueue_callback($callback);
        };
        $this->timer_callback = function ($resource, $what, Timer_Callback $callback): void {
            $this->enqueue_callback($callback);
        };
        $this->signal_callback = function ($signo, $what, Signal_Callback $callback): void {
            $this->enqueue_callback($callback);
        };
    }
    /**
     * {@inheritdoc}
     */
    public function cancel(string $callback_id): void
    {
        parent::cancel($callback_id);
        if (isset($this->events[$callback_id])) {
            $this->events[$callback_id]->free();
            unset($this->events[$callback_id]);
        }
    }
    /**
     * @codeCoverageIgnore
     */
    public function __destruct()
    {
        foreach ($this->events as $event) {
            if ($event !== null) {
                // Events may have been nulled in extension depending on destruct order.
                $event->free();
            }
        }
        // Unset here, otherwise $event->del() fails with a warning, because __destruct order isn't defined.
        // See https://github.com/amphp/amp/issues/159.
        $this->events = [];
        // Manually free the loop handle to fully release loop resources.
        // See https://github.com/amphp/amp/issues/177.
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        if (isset($this->handle)) {
            $this->handle->free();
            unset($this->handle);
        }
    }
    /**
     * {@inheritdoc}
     */
    public function run(): void
    {
        $active = self::$active_signals;
        \assert($active !== null);
        foreach ($active as $event) {
            $event->del();
        }
        self::$active_signals =& $this->signals;
        foreach ($this->signals as $event) {
            /** @psalm-suppress TooFewArguments https://github.com/JetBrains/phpstorm-stubs/pull/763 */
            $event->add();
        }
        try {
            parent::run();
        } finally {
            foreach ($this->signals as $event) {
                $event->del();
            }
            self::$active_signals =& $active;
            foreach ($active as $event) {
                /** @psalm-suppress TooFewArguments https://github.com/JetBrains/phpstorm-stubs/pull/763 */
                $event->add();
            }
        }
    }
    /**
     * {@inheritdoc}
     */
    public function stop(): void
    {
        $this->handle->stop();
        parent::stop();
    }
    /**
     * {@inheritdoc}
     */
    public function get_handle(): \Event_Base
    {
        return $this->handle;
    }
    protected function now(): float
    {
        return (float) \hrtime(true) / 1000000000;
    }
    /**
     * {@inheritdoc}
     */
    protected function dispatch(bool $blocking): void
    {
        $this->handle->loop($blocking ? \Event_Base::LOOP_ONCE : \Event_Base::LOOP_ONCE | \Event_Base::LOOP_NONBLOCK);
    }
    /**
     * {@inheritdoc}
     */
    protected function activate(array $callbacks): void
    {
        $now = $this->now();
        foreach ($callbacks as $callback) {
            if (!isset($this->events[$id = $callback->id])) {
                if ($callback instanceof Stream_Readable_Callback) {
                    \assert(\is_resource($callback->stream));
                    $this->events[$id] = new \Event($this->handle, $callback->stream, \Event::READ | \Event::PERSIST, $this->io_callback, $callback);
                } elseif ($callback instanceof Stream_Writable_Callback) {
                    \assert(\is_resource($callback->stream));
                    $this->events[$id] = new \Event($this->handle, $callback->stream, \Event::WRITE | \Event::PERSIST, $this->io_callback, $callback);
                } elseif ($callback instanceof Timer_Callback) {
                    $this->events[$id] = new \Event($this->handle, -1, \Event::TIMEOUT, $this->timer_callback, $callback);
                } elseif ($callback instanceof Signal_Callback) {
                    $this->events[$id] = new \Event($this->handle, $callback->signal, \Event::SIGNAL | \Event::PERSIST, $this->signal_callback, $callback);
                } else {
                    // @codeCoverageIgnoreStart
                    throw new \Error('Unknown callback type');
                    // @codeCoverageIgnoreEnd
                }
            }
            if ($callback instanceof Timer_Callback) {
                $interval = \min(\max(0, $callback->expiration - $now), \PHP_INT_MAX / 2);
                $this->events[$id]->add($interval > 0 ? $interval : 0);
            } elseif ($callback instanceof Signal_Callback) {
                $this->signals[$id] = $this->events[$id];
                /** @psalm-suppress TooFewArguments https://github.com/JetBrains/phpstorm-stubs/pull/763 */
                $this->events[$id]->add();
            } else {
                /** @psalm-suppress TooFewArguments https://github.com/JetBrains/phpstorm-stubs/pull/763 */
                $this->events[$id]->add();
            }
        }
    }
    /**
     * {@inheritdoc}
     */
    protected function deactivate(Driver_Callback $callback): void
    {
        if (isset($this->events[$id = $callback->id])) {
            $this->events[$id]->del();
            if ($callback instanceof Signal_Callback) {
                unset($this->signals[$id]);
            }
        }
    }
}