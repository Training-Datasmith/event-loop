<?php

declare (strict_types=1);
namespace Revolt\Event_Loop;

enum Callback_Type
{
    case Defer;
    case Delay;
    case Repeat;
    case Readable;
    case Writable;
    case Signal;
}