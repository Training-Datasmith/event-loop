<?php

declare (strict_types=1);
namespace Revolt\Event_Loop\Driver;

use Revolt\Event_Loop\Internal\Abstract_Driver;
use Revolt\Event_Loop\Internal\Driver_Callback;
use Revolt\Event_Loop\Internal\Signal_Callback;
use Revolt\Event_Loop\Internal\Stream_Callback;
use Revolt\Event_Loop\Internal\Stream_Readable_Callback;
use Revolt\Event_Loop\Internal\Stream_Writable_Callback;
use Revolt\Event_Loop\Internal\Timer_Callback;
final class Uv_Driver extends Abstract_Driver
{
    public static function is_supported(): bool
    {
        return \extension_loaded('uv');
    }
    /** @var resource|\UVLoop A uv_loop resource created with uv_loop_new() */
    private $handle;
    /** @var array<string, resource> */
    private array $events = [];
    /** @var array<int, array<array-key, DriverCallback>> */
    private array $uv_callbacks = [];
    /** @var array<int, resource> */
    private array $streams = [];
    private readonly \Closure $io_callback;
    private readonly \Closure $timer_callback;
    private readonly \Closure $signal_callback;
    public function __construct()
    {
        parent::__construct();
        $this->handle = \uv_loop_new();
        $this->io_callback = function ($event, $status, $events, $resource): void {
            $callbacks = $this->uv_callbacks[(int) $event];
            // Invoke the callback on errors, as this matches behavior with other loop back-ends.
            // Re-enable callback as libuv disables the callback on non-zero status.
            if ($status !== 0) {
                $flags = 0;
                foreach ($callbacks as $callback) {
                    \assert($callback instanceof Stream_Callback);
                    $flags |= $callback->invokable ? $this->get_stream_callback_flags($callback) : 0;
                }
                \uv_poll_start($event, $flags, $this->io_callback);
            }
            foreach ($callbacks as $callback) {
                \assert($callback instanceof Stream_Callback);
                // $events is ORed with 4 to trigger callback if no events are indicated (0) or on UV_DISCONNECT (4).
                // http://docs.libuv.org/en/v1.x/poll.html
                if (!($this->get_stream_callback_flags($callback) & $events || ($events | 4) === 4)) {
                    continue;
                }
                $this->enqueue_callback($callback);
            }
        };
        $this->timer_callback = function ($event): void {
            $callback = $this->uv_callbacks[(int) $event][0];
            \assert($callback instanceof Timer_Callback);
            $this->enqueue_callback($callback);
        };
        $this->signal_callback = function ($event): void {
            $callback = $this->uv_callbacks[(int) $event][0];
            $this->enqueue_callback($callback);
        };
    }
    /**
     * {@inheritdoc}
     */
    public function cancel(string $callback_id): void
    {
        parent::cancel($callback_id);
        if (!isset($this->events[$callback_id])) {
            return;
        }
        $event = $this->events[$callback_id];
        $event_id = (int) $event;
        if (isset($this->uv_callbacks[$event_id][0])) {
            // All except IO callbacks.
            unset($this->uv_callbacks[$event_id]);
        } elseif (isset($this->uv_callbacks[$event_id][$callback_id])) {
            $callback = $this->uv_callbacks[$event_id][$callback_id];
            unset($this->uv_callbacks[$event_id][$callback_id]);
            \assert($callback instanceof Stream_Callback);
            if (empty($this->uv_callbacks[$event_id])) {
                unset($this->uv_callbacks[$event_id], $this->streams[(int) $callback->stream]);
            }
        }
        unset($this->events[$callback_id]);
    }
    /**
     * @return \UVLoop|resource
     */
    public function get_handle(): mixed
    {
        return $this->handle;
    }
    protected function now(): float
    {
        \uv_update_time($this->handle);
        /** @psalm-suppress TooManyArguments */
        return \uv_now($this->handle) / 1000;
    }
    /**
     * {@inheritdoc}
     */
    protected function dispatch(bool $blocking): void
    {
        /** @psalm-suppress TooManyArguments */
        \uv_run($this->handle, $blocking ? \UV::RUN_ONCE : \UV::RUN_NOWAIT);
    }
    /**
     * {@inheritdoc}
     */
    protected function activate(array $callbacks): void
    {
        $now = $this->now();
        foreach ($callbacks as $callback) {
            $id = $callback->id;
            if ($callback instanceof Stream_Callback) {
                \assert(\is_resource($callback->stream));
                $stream_id = (int) $callback->stream;
                if (isset($this->streams[$stream_id])) {
                    $event = $this->streams[$stream_id];
                } elseif (isset($this->events[$id])) {
                    $event = $this->streams[$stream_id] = $this->events[$id];
                } else {
                    /** @psalm-suppress TooManyArguments */
                    $event = $this->streams[$stream_id] = \uv_poll_init_socket($this->handle, $callback->stream);
                }
                $event_id = (int) $event;
                $this->events[$id] = $event;
                $this->uv_callbacks[$event_id][$id] = $callback;
                $flags = 0;
                foreach ($this->uv_callbacks[$event_id] as $w) {
                    \assert($w instanceof Stream_Callback);
                    $flags |= $w->enabled ? $this->get_stream_callback_flags($w) : 0;
                }
                \uv_poll_start($event, $flags, $this->io_callback);
            } elseif ($callback instanceof Timer_Callback) {
                if (isset($this->events[$id])) {
                    $event = $this->events[$id];
                } else {
                    $event = $this->events[$id] = \uv_timer_init($this->handle);
                }
                $this->uv_callbacks[(int) $event] = [$callback];
                \uv_timer_start($event, (int) \min(\max(0, \ceil(($callback->expiration - $now) * 1000)), \PHP_INT_MAX), $callback->repeat ? (int) \min(\max(0, \ceil($callback->interval * 1000)), \PHP_INT_MAX) : 0, $this->timer_callback);
            } elseif ($callback instanceof Signal_Callback) {
                if (isset($this->events[$id])) {
                    $event = $this->events[$id];
                } else {
                    /** @psalm-suppress TooManyArguments */
                    $event = $this->events[$id] = \uv_signal_init($this->handle);
                }
                $this->uv_callbacks[(int) $event] = [$callback];
                /** @psalm-suppress TooManyArguments */
                \uv_signal_start($event, $this->signal_callback, $callback->signal);
            } else {
                // @codeCoverageIgnoreStart
                throw new \Error('Unknown callback type');
                // @codeCoverageIgnoreEnd
            }
        }
    }
    /**
     * {@inheritdoc}
     */
    protected function deactivate(Driver_Callback $callback): void
    {
        $id = $callback->id;
        if (!isset($this->events[$id])) {
            return;
        }
        $event = $this->events[$id];
        if (!\uv_is_active($event)) {
            return;
        }
        if ($callback instanceof Stream_Callback) {
            $flags = 0;
            foreach ($this->uv_callbacks[(int) $event] as $w) {
                \assert($w instanceof Stream_Callback);
                $flags |= $w->invokable ? $this->get_stream_callback_flags($w) : 0;
            }
            if ($flags) {
                \uv_poll_start($event, $flags, $this->io_callback);
            } else {
                \uv_poll_stop($event);
            }
        } elseif ($callback instanceof Timer_Callback) {
            \uv_timer_stop($event);
        } elseif ($callback instanceof Signal_Callback) {
            \uv_signal_stop($event);
        } else {
            // @codeCoverageIgnoreStart
            throw new \Error('Unknown callback type');
            // @codeCoverageIgnoreEnd
        }
    }
    private function get_stream_callback_flags(Stream_Callback $callback): int
    {
        if ($callback instanceof Stream_Writable_Callback) {
            return \UV::WRITABLE;
        }
        if ($callback instanceof Stream_Readable_Callback) {
            return \UV::READABLE;
        }
        throw new \Error('Invalid callback type');
    }
}