<?php

declare (strict_types=1);
namespace Revolt\Event_Loop\Internal;

/** @internal */
final class Closure_Helper
{
    public static function get_description(\Closure $closure): string
    {
        try {
            $reflection = new \ReflectionFunction($closure);
            $description = $reflection->name;
            if ($scope_class = $reflection->get_closure_scope_class()) {
                $description = $scope_class->name . '::' . $description;
            }
            if ($reflection->get_file_name() !== false && $reflection->get_start_line()) {
                $description .= ' defined in ' . $reflection->get_file_name() . ':' . $reflection->get_start_line();
            }
            return $description;
        } catch (\Reflection_Exception) {
            return '???';
        }
    }
}