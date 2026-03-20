<?php

declare (strict_types=1);
/** @noinspection PhpComposerExtensionStubsInspection */
namespace Revolt\Event_Loop\Driver;

use Revolt\Event_Loop\Internal\Abstract_Driver;
use Revolt\Event_Loop\Internal\Driver_Callback;
use Revolt\Event_Loop\Internal\Signal_Callback;
use Revolt\Event_Loop\Internal\Stream_Readable_Callback;
use Revolt\Event_Loop\Internal\Stream_Writable_Callback;
use Revolt\Event_Loop\Internal\Timer_Callback;
use Revolt\Event_Loop\Internal\Timer_Queue;
use Revolt\Event_Loop\Unsupported_Feature_Exception;
final class Stream_Select_Driver extends Abstract_Driver
{
    /** @var array<int, resource> */
    private array $read_streams = [];
    /** @var array<int, array<string, StreamReadableCallback>> */
    private array $read_callbacks = [];
    /** @var array<int, resource> */
    private array $write_streams = [];
    /** @var array<int, array<string, StreamWritableCallback>> */
    private array $write_callbacks = [];
    private readonly Timer_Queue $timer_queue;
    /** @var array<int, array<string, SignalCallback>> */
    private array $signal_callbacks = [];
    /** @var \SplQueue<int> */
    private readonly \SplQueue $signal_queue;
    private readonly bool $signal_handling;
    private readonly \Closure $stream_select_error_handler;
    private bool $stream_select_ignore_result = false;
    public function __construct()
    {
        parent::__construct();
        $this->signal_queue = new \SplQueue();
        $this->timer_queue = new Timer_Queue();
        $this->signal_handling = \extension_loaded('pcntl') && \function_exists('pcntl_signal_dispatch') && \function_exists('pcntl_signal');
        $this->stream_select_error_handler = function (int $errno, string $message): void {
            // Casing changed in PHP 8 from 'unable' to 'Unable'
            if (\stripos($message, 'stream_select(): unable to select [4]: ') === 0) {
                // EINTR
                $this->stream_select_ignore_result = true;
                return;
            }
            if (\str_contains($message, 'FD_SETSIZE')) {
                $message = \str_replace(["\r\n", "\n", "\r"], ' ', $message);
                $pattern = '(stream_select\(\): You MUST recompile PHP with a larger value of FD_SETSIZE. It is set to (\d+), but you have descriptors numbered at least as high as (\d+)\.)';
                if (\preg_match($pattern, $message, $match)) {
                    $help_link = 'https://revolt.run/extensions';
                    $message = 'You have reached the limits of stream_select(). It has a FD_SETSIZE of ' . $match[1] . ', but you have file descriptors numbered at least as high as ' . $match[2] . '. ' . "You can install one of the extensions listed on {$help_link} to support a higher number of " . 'concurrent file descriptors. If a large number of open file descriptors is unexpected, you ' . "might be leaking file descriptors that aren't closed correctly.";
                }
            }
            throw new \Exception($message, $errno);
        };
    }
    public function __destruct()
    {
        foreach ($this->signal_callbacks as $signal_callbacks) {
            foreach ($signal_callbacks as $signal_callback) {
                $this->deactivate($signal_callback);
            }
        }
    }
    /**
     * @throws UnsupportedFeatureException If the pcntl extension is not available.
     */
    public function on_signal(int $signal, \Closure $closure): string
    {
        if (!$this->signal_handling) {
            throw new Unsupported_Feature_Exception('Signal handling requires the pcntl extension');
        }
        return parent::on_signal($signal, $closure);
    }
    public function get_handle(): mixed
    {
        return null;
    }
    protected function now(): float
    {
        return (float) \hrtime(true) / 1000000000;
    }
    /**
     * @throws \Throwable
     */
    protected function dispatch(bool $blocking): void
    {
        if ($this->signal_handling) {
            \pcntl_signal_dispatch();
            while (!$this->signal_queue->is_empty()) {
                $signal = $this->signal_queue->dequeue();
                foreach ($this->signal_callbacks[$signal] as $callback) {
                    $this->enqueue_callback($callback);
                }
                $blocking = false;
            }
        }
        $this->select_streams($this->read_streams, $this->write_streams, $blocking ? $this->get_timeout() : 0.0);
        $now = $this->now();
        while ($callback = $this->timer_queue->extract($now)) {
            $this->enqueue_callback($callback);
        }
    }
    protected function activate(array $callbacks): void
    {
        foreach ($callbacks as $callback) {
            if ($callback instanceof Stream_Readable_Callback) {
                \assert(\is_resource($callback->stream));
                $stream_id = (int) $callback->stream;
                $this->read_callbacks[$stream_id][$callback->id] = $callback;
                $this->read_streams[$stream_id] = $callback->stream;
            } elseif ($callback instanceof Stream_Writable_Callback) {
                \assert(\is_resource($callback->stream));
                $stream_id = (int) $callback->stream;
                $this->write_callbacks[$stream_id][$callback->id] = $callback;
                $this->write_streams[$stream_id] = $callback->stream;
            } elseif ($callback instanceof Timer_Callback) {
                $this->timer_queue->insert($callback);
            } elseif ($callback instanceof Signal_Callback) {
                if (!isset($this->signal_callbacks[$callback->signal])) {
                    \set_error_handler(static function (int $errno, string $errstr): bool {
                        throw new Unsupported_Feature_Exception(\sprintf('Failed to register signal handler; Errno: %d; %s', $errno, $errstr));
                    });
                    // Avoid bug in Psalm handling of first-class callables by assigning to a temp variable.
                    $handler = $this->handle_signal(...);
                    try {
                        \pcntl_signal($callback->signal, $handler);
                    } finally {
                        \restore_error_handler();
                    }
                }
                $this->signal_callbacks[$callback->signal][$callback->id] = $callback;
            } else {
                // @codeCoverageIgnoreStart
                throw new \Error('Unknown callback type');
                // @codeCoverageIgnoreEnd
            }
        }
    }
    protected function deactivate(Driver_Callback $callback): void
    {
        if ($callback instanceof Stream_Readable_Callback) {
            $stream_id = (int) $callback->stream;
            unset($this->read_callbacks[$stream_id][$callback->id]);
            if (empty($this->read_callbacks[$stream_id])) {
                unset($this->read_callbacks[$stream_id], $this->read_streams[$stream_id]);
            }
        } elseif ($callback instanceof Stream_Writable_Callback) {
            $stream_id = (int) $callback->stream;
            unset($this->write_callbacks[$stream_id][$callback->id]);
            if (empty($this->write_callbacks[$stream_id])) {
                unset($this->write_callbacks[$stream_id], $this->write_streams[$stream_id]);
            }
        } elseif ($callback instanceof Timer_Callback) {
            $this->timer_queue->remove($callback);
        } elseif ($callback instanceof Signal_Callback) {
            if (isset($this->signal_callbacks[$callback->signal])) {
                unset($this->signal_callbacks[$callback->signal][$callback->id]);
                if (empty($this->signal_callbacks[$callback->signal])) {
                    unset($this->signal_callbacks[$callback->signal]);
                    \set_error_handler(static fn(): bool => true);
                    try {
                        \pcntl_signal($callback->signal, \SIG_DFL);
                    } finally {
                        \restore_error_handler();
                    }
                }
            }
        } else {
            // @codeCoverageIgnoreStart
            throw new \Error('Unknown callback type');
            // @codeCoverageIgnoreEnd
        }
    }
    /**
     * @param array<int, resource> $read
     * @param array<int, resource> $write
     */
    private function select_streams(array $read, array $write, float $timeout): void
    {
        if (!empty($read) || !empty($write)) {
            // Use stream_select() if there are any streams in the loop.
            if ($timeout >= 0) {
                $seconds = (int) $timeout;
                $microseconds = (int) (($timeout - $seconds) * 1000000);
            } else {
                $seconds = null;
                $microseconds = null;
            }
            // Failed connection attempts are indicated via except on Windows
            // @link https://github.com/reactphp/event-loop/blob/8bd064ce23c26c4decf186c2a5a818c9a8209eb0/src/StreamSelectLoop.php#L279-L287
            // @link https://docs.microsoft.com/de-de/windows/win32/api/winsock2/nf-winsock2-select
            $except = null;
            if (\DIRECTORY_SEPARATOR === '\\') {
                $except = $write;
            }
            \set_error_handler($this->stream_select_error_handler);
            try {
                /** @psalm-suppress InvalidArgument */
                $result = \stream_select($read, $write, $except, $seconds, $microseconds);
            } finally {
                \restore_error_handler();
            }
            if ($this->stream_select_ignore_result || $result === 0) {
                $this->stream_select_ignore_result = false;
                return;
            }
            if (!$result) {
                throw new \Exception('Unknown error during stream_select');
            }
            foreach ($read as $stream) {
                $stream_id = (int) $stream;
                if (!isset($this->read_callbacks[$stream_id])) {
                    continue;
                    // All read callbacks disabled.
                }
                foreach ($this->read_callbacks[$stream_id] as $callback) {
                    $this->enqueue_callback($callback);
                }
            }
            /** @var array<int, resource>|null $except */
            if ($except !== null) {
                foreach ($except as $key => $socket) {
                    $write[$key] = $socket;
                }
            }
            foreach ($write as $stream) {
                $stream_id = (int) $stream;
                if (!isset($this->write_callbacks[$stream_id])) {
                    continue;
                    // All write callbacks disabled.
                }
                foreach ($this->write_callbacks[$stream_id] as $callback) {
                    $this->enqueue_callback($callback);
                }
            }
            return;
        }
        if ($timeout < 0) {
            // Only signal callbacks are enabled, so sleep indefinitely.
            /** @psalm-suppress ArgumentTypeCoercion */
            \usleep(\PHP_INT_MAX);
            return;
        }
        if ($timeout > 0) {
            // Sleep until next timer expires.
            /** @psalm-suppress ArgumentTypeCoercion $timeout is positive here. */
            \usleep((int) ($timeout * 1000000));
        }
    }
    /**
     * @return float Seconds until next timer expires or -1 if there are no pending timers.
     */
    private function get_timeout(): float
    {
        $expiration = $this->timer_queue->peek();
        if ($expiration === null) {
            return -1;
        }
        $expiration -= $this->now();
        return $expiration > 0 ? $expiration : 0.0;
    }
    private function handle_signal(int $signal): void
    {
        // Queue signals, so we don't suspend inside pcntl_signal_dispatch, which disables signals while it runs
        $this->signal_queue->enqueue($signal);
    }
}