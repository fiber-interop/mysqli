<?php

namespace fiber\mysqli;

/** Driver state. Adapters write $candidates directly from their files autoload (it may run before fiber\mysqli\register() is defined). */
final class Registry
{
    public static ?Driver $driver = null;
    /** @var array<string, callable(): Driver> */
    public static array $candidates = [];
    /** @var \WeakMap<\mysqli, object>|null The last query started on each connection. */
    public static ?\WeakMap $last = null;
}
