# Architecture: event-loop

## Purpose

An async event loop for PHP. It provides a unified API for scheduling timers, watching file descriptors, handling UNIX signals, and suspending/resuming fibers. It auto-selects the best available backend (libuv, libev, libevent, or pure PHP `stream_select`).

## Directory Structure

```
src/
  Event_Loop.php                            — Static facade: EventLoop::run(), defer(), delay(), etc.
  EventLoop/
    Driver.php                              — Interface every backend must implement
    Driver_Factory.php                      — Detects and instantiates the best available driver
    Callback_Type.php                       — Enum: DEFER, DELAY, REPEAT, STREAM_READABLE, STREAM_WRITABLE, SIGNAL
    Suspension.php                          — Interface for fiber suspension/resumption
    Fiber_Local.php                         — Fiber-local storage (analogous to thread-local variables)
    Unsupported_Feature_Exception.php       — Thrown when a driver lacks a requested feature (e.g., signals on Windows)
    Uncaught_Throwable.php                  — Wraps unhandled exceptions from event callbacks
    Invalid_Callback_Error.php              — Thrown when an invalid callback ID is used

    Driver/
      Stream_Select_Driver.php              — Pure PHP fallback using stream_select() (always available)
      Ev_Driver.php                         — libev backend via php-ev extension
      Event_Driver.php                      — libevent backend via php-event extension
      Uv_Driver.php                         — libuv backend via php-uv extension
      Tracing_Driver.php                    — Debug wrapper: logs all event loop activity

    Internal/
      Abstract_Driver.php                   — Base class: callback management, error handling, fiber integration
      Driver_Suspension.php                 — Fiber-aware suspend/resume implementation
      Driver_Callback.php                   — Base value object for a registered callback
      Defer_Callback.php                    — Callback scheduled for the next iteration
      Timer_Callback.php                    — One-shot or repeating timer callback
      Stream_Callback.php                   — Base for readable/writable stream callbacks
      Signal_Callback.php                   — UNIX signal callback
      Closure_Helper.php                    — Utilities for wrapping closures with error handling
      Timer_Queue.php                       — Min-heap priority queue for timer scheduling

examples/
  timers.php / benchmark-timers.php        — Timer scheduling examples
  http-server.php / http-client-async.php  — Async I/O examples
  fiber-local-*.php                        — Fiber-local storage examples
```

## Key Design Decisions

- **Driver auto-selection** — `Driver_Factory` prefers libuv > libev > libevent > stream_select, choosing the most capable backend available at runtime.
- **Fiber integration** — all drivers integrate with PHP 8.1 fibers via `Driver_Suspension`, enabling `async/await`-style code without callbacks.
- **Fiber-local storage** — `Fiber_Local` provides per-fiber storage that is automatically cleaned up when a fiber completes, preventing cross-fiber state leakage.
- **Static facade** — `Event_Loop` provides convenience static methods that delegate to the current driver, reducing boilerplate in application code.
- **Tracing driver** — wraps any driver to log every callback registration and invocation, simplifying debugging of complex event flows.

## Extension Points

- Implement `Driver` to add a new backend (e.g., a ReactPHP bridge).
- Use `Fiber_Local` to store per-fiber state without manual cleanup.

## Dependency Flow

```
EventLoop::run()
  └── Driver (selected by Driver_Factory)
        ├── Timer_Queue (min-heap of pending timers)
        ├── stream_select / ext-ev / ext-event / ext-uv
        └── Driver_Suspension (fiber suspend/resume)
```
