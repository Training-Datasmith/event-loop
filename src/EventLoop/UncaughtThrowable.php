<?php

declare (strict_types=1);
namespace Revolt\Event_Loop;

use Revolt\Event_Loop\Internal\Closure_Helper;
final class Uncaught_Throwable extends \Error
{
    public static function throwing_callback(\Closure $closure, \Throwable $previous): self
    {
        return new self("Uncaught %s thrown in event loop callback %s; use Revolt\\EventLoop::setErrorHandler() to gracefully handle such exceptions%s", $closure, $previous);
    }
    public static function throwing_error_handler(\Closure $closure, \Throwable $previous): self
    {
        return new self('Uncaught %s thrown in event loop error handler %s%s', $closure, $previous);
    }
    private function __construct(string $message, \Closure $closure, \Throwable $previous)
    {
        parent::__construct(\sprintf(
            $message,
            \str_replace("\x00", '@', $previous::class),
            // replace NUL-byte in anonymous class name
            Closure_Helper::get_description($closure),
            $previous->get_message() !== '' ? ': ' . $previous->get_message() : ''
        ), 0, $previous);
    }
}