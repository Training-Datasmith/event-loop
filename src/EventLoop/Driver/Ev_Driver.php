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
final class Ev_Driver extends Abstract_Driver
{
    /** @var array<string, \EvSignal>|null */
    private static ?array $active_signals = null;
    public static function is_supported(): bool
    {
        return \extension_loaded('ev');
    }
    private readonly \Ev_Loop $handle;
    /** @var array<string, \EvWatcher> */
    private array $events = [];
    private readonly \Closure $io_callback;
    private readonly \Closure $timer_callback;
    private readonly \Closure $signal_callback;
    /** @var array<string, \EvSignal> */
    private array $signals = [];
    public function __construct()
    {
        parent::__construct();
        $this->handle = new \Ev_Loop();
        if (self::$active_signals === null) {
            self::$active_signals =& $this->signals;
        }
        $this->io_callback = function (\Ev_Io $event): void {
            /** @var StreamCallback $callback */
            $callback = $event->data;
            $this->enqueue_callback($callback);
        };
        $this->timer_callback = function (\Ev_Timer $event): void {
            /** @var TimerCallback $callback */
            $callback = $event->data;
            $this->enqueue_callback($callback);
        };
        $this->signal_callback = function (\Ev_Signal $event): void {
            /** @var SignalCallback $callback */
            $callback = $event->data;
            $this->enqueue_callback($callback);
        };
    }
    /**
     * {@inheritdoc}
     */
    public function cancel(string $callback_id): void
    {
        parent::cancel($callback_id);
        unset($this->events[$callback_id]);
    }
    public function __destruct()
    {
        foreach ($this->events as $event) {
            /** @psalm-suppress all */
            if ($event !== null) {
                // Events may have been nulled in extension depending on destruct order.
                $event->stop();
            }
        }
        // We need to clear all references to events manually, see
        // https://bitbucket.org/osmanov/pecl-ev/issues/31/segfault-in-ev_timer_stop
        $this->events = [];
    }
    /**
     * {@inheritdoc}
     */
    public function run(): void
    {
        $active = self::$active_signals;
        \assert($active !== null);
        foreach ($active as $event) {
            $event->stop();
        }
        self::$active_signals =& $this->signals;
        foreach ($this->signals as $event) {
            $event->start();
        }
        try {
            parent::run();
        } finally {
            foreach ($this->signals as $event) {
                $event->stop();
            }
            self::$active_signals =& $active;
            foreach ($active as $event) {
                $event->start();
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
    public function get_handle(): \Ev_Loop
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
        $this->handle->run($blocking ? \Ev::RUN_ONCE : \Ev::RUN_ONCE | \Ev::RUN_NOWAIT);
    }
    /**
     * {@inheritdoc}
     */
    protected function activate(array $callbacks): void
    {
        $this->handle->now_update();
        $now = $this->now();
        foreach ($callbacks as $callback) {
            if (!isset($this->events[$id = $callback->id])) {
                if ($callback instanceof Stream_Readable_Callback) {
                    \assert(\is_resource($callback->stream));
                    $this->events[$id] = $this->handle->io($callback->stream, \Ev::READ, $this->io_callback, $callback);
                } elseif ($callback instanceof Stream_Writable_Callback) {
                    \assert(\is_resource($callback->stream));
                    $this->events[$id] = $this->handle->io($callback->stream, \Ev::WRITE, $this->io_callback, $callback);
                } elseif ($callback instanceof Timer_Callback) {
                    $interval = $callback->interval;
                    $this->events[$id] = $this->handle->timer(\max(0, $callback->expiration - $now), $callback->repeat ? $interval : 0, $this->timer_callback, $callback);
                } elseif ($callback instanceof Signal_Callback) {
                    $this->events[$id] = $this->handle->signal($callback->signal, $this->signal_callback, $callback);
                } else {
                    // @codeCoverageIgnoreStart
                    throw new \Error('Unknown callback type: ' . $callback::class);
                    // @codeCoverageIgnoreEnd
                }
            } else {
                $this->events[$id]->start();
            }
            if ($callback instanceof Signal_Callback) {
                /** @psalm-suppress PropertyTypeCoercion */
                $this->signals[$id] = $this->events[$id];
            }
        }
    }
    protected function deactivate(Driver_Callback $callback): void
    {
        if (isset($this->events[$id = $callback->id])) {
            $this->events[$id]->stop();
            if ($callback instanceof Signal_Callback) {
                unset($this->signals[$id]);
            }
        }
    }
}