<?php

declare (strict_types=1);
namespace Revolt\Event_Loop\Driver;

use Revolt\Event_Loop\Callback_Type;
use Revolt\Event_Loop\Driver;
use Revolt\Event_Loop\Invalid_Callback_Error;
use Revolt\Event_Loop\Suspension;
final class Tracing_Driver implements Driver
{
    /** @var array<string, true> */
    private array $enabled_callbacks = [];
    /** @var array<string, true> */
    private array $unreferenced_callbacks = [];
    /** @var array<string, string> */
    private array $creation_traces = [];
    /** @var array<string, string> */
    private array $cancel_traces = [];
    public function __construct(private readonly Driver $driver)
    {
    }
    public function run(): void
    {
        $this->driver->run();
    }
    public function stop(): void
    {
        $this->driver->stop();
    }
    public function get_suspension(): Suspension
    {
        return $this->driver->get_suspension();
    }
    public function is_running(): bool
    {
        return $this->driver->is_running();
    }
    public function defer(\Closure $closure): string
    {
        $id = $this->driver->defer(function (...$args) use ($closure) {
            $this->cancel($args[0]);
            return $closure(...$args);
        });
        $this->creation_traces[$id] = $this->format_stacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabled_callbacks[$id] = true;
        return $id;
    }
    public function delay(float $delay, \Closure $closure): string
    {
        $id = $this->driver->delay($delay, function (...$args) use ($closure) {
            $this->cancel($args[0]);
            return $closure(...$args);
        });
        $this->creation_traces[$id] = $this->format_stacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabled_callbacks[$id] = true;
        return $id;
    }
    public function repeat(float $interval, \Closure $closure): string
    {
        $id = $this->driver->repeat($interval, $closure);
        $this->creation_traces[$id] = $this->format_stacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabled_callbacks[$id] = true;
        return $id;
    }
    public function on_readable(mixed $stream, \Closure $closure): string
    {
        $id = $this->driver->on_readable($stream, $closure);
        $this->creation_traces[$id] = $this->format_stacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabled_callbacks[$id] = true;
        return $id;
    }
    public function on_writable(mixed $stream, \Closure $closure): string
    {
        $id = $this->driver->on_writable($stream, $closure);
        $this->creation_traces[$id] = $this->format_stacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabled_callbacks[$id] = true;
        return $id;
    }
    public function on_signal(int $signal, \Closure $closure): string
    {
        $id = $this->driver->on_signal($signal, $closure);
        $this->creation_traces[$id] = $this->format_stacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabled_callbacks[$id] = true;
        return $id;
    }
    public function enable(string $callback_id): string
    {
        try {
            $this->driver->enable($callback_id);
            $this->enabled_callbacks[$callback_id] = true;
        } catch (Invalid_Callback_Error $e) {
            $e->add_info('Creation trace', $this->get_creation_trace($callback_id));
            $e->add_info('Cancellation trace', $this->get_cancel_trace($callback_id));
            throw $e;
        }
        return $callback_id;
    }
    public function cancel(string $callback_id): void
    {
        $this->driver->cancel($callback_id);
        if (!isset($this->cancel_traces[$callback_id])) {
            $this->cancel_traces[$callback_id] = $this->format_stacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        }
        unset($this->enabled_callbacks[$callback_id], $this->unreferenced_callbacks[$callback_id]);
    }
    public function disable(string $callback_id): string
    {
        $this->driver->disable($callback_id);
        unset($this->enabled_callbacks[$callback_id]);
        return $callback_id;
    }
    public function reference(string $callback_id): string
    {
        try {
            $this->driver->reference($callback_id);
            unset($this->unreferenced_callbacks[$callback_id]);
        } catch (Invalid_Callback_Error $e) {
            $e->add_info('Creation trace', $this->get_creation_trace($callback_id));
            $e->add_info('Cancellation trace', $this->get_cancel_trace($callback_id));
            throw $e;
        }
        return $callback_id;
    }
    public function unreference(string $callback_id): string
    {
        $this->driver->unreference($callback_id);
        $this->unreferenced_callbacks[$callback_id] = true;
        return $callback_id;
    }
    public function set_error_handler(?\Closure $error_handler): void
    {
        $this->driver->set_error_handler($error_handler);
    }
    public function get_error_handler(): ?\Closure
    {
        return $this->driver->get_error_handler();
    }
    /** @inheritdoc */
    public function get_handle(): mixed
    {
        return $this->driver->get_handle();
    }
    public function dump(): string
    {
        $dump = 'Enabled, referenced callbacks keeping the loop running: ';
        foreach ($this->enabled_callbacks as $callback_id => $_) {
            if (isset($this->unreferenced_callbacks[$callback_id])) {
                continue;
            }
            $dump .= 'Callback identifier: ' . $callback_id . "\r\n";
            $dump .= $this->get_creation_trace($callback_id);
            $dump .= "\r\n\r\n";
        }
        return \rtrim($dump);
    }
    public function get_identifiers(): array
    {
        return $this->driver->get_identifiers();
    }
    public function get_type(string $callback_id): Callback_Type
    {
        return $this->driver->get_type($callback_id);
    }
    public function is_enabled(string $callback_id): bool
    {
        return $this->driver->is_enabled($callback_id);
    }
    public function is_referenced(string $callback_id): bool
    {
        return $this->driver->is_referenced($callback_id);
    }
    public function __debugInfo(): array
    {
        return $this->driver->__debugInfo();
    }
    public function queue(\Closure $closure, mixed ...$args): void
    {
        $this->driver->queue($closure, ...$args);
    }
    private function get_creation_trace(string $callback_id): string
    {
        return $this->creation_traces[$callback_id] ?? 'No creation trace, yet.';
    }
    private function get_cancel_trace(string $callback_id): string
    {
        return $this->cancel_traces[$callback_id] ?? 'No cancellation trace, yet.';
    }
    /**
     * Formats a stacktrace obtained via `debug_backtrace()`.
     *
     * @param list<array{
     *     args?: list<mixed>,
     *     class?: class-string,
     *     file?: string,
     *     function: string,
     *     line?: int,
     *     object?: object,
     *     type?: string
     * }> $trace Output of `debug_backtrace()`.
     *
     * @return string Formatted stacktrace.
     */
    private function format_stacktrace(array $trace): string
    {
        return \implode("\n", \array_map(static function (array|int $e, int $i): string {
            $line = "#{$i} ";
            if (isset($e['file'], $e['line'])) {
                $line .= "{$e['file']}:{$e['line']} ";
            }
            if (isset($e['class'], $e['type'])) {
                $line .= $e['class'] . $e['type'];
            }
            return $line . $e['function'] . '()';
        }, $trace, \array_keys($trace)));
    }
}