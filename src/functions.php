<?php
/**
 * fiber-interop/mysqli: cooperative mysqli queries on any fiber-interop adapter.
 */

namespace async\mysqli;

if (\function_exists('async\mysqli\query')) {
    return;
}

/**
 * Same contract as $m->query($sql, $resultMode): returns a mysqli_result, true or false, leaves
 * $m->errno/$m->error set, and throws mysqli_sql_exception where mysqli_report() says so. Inside
 * async\run() only the calling task waits; outside run() this is a plain blocking query().
 */
function query(\mysqli $m, string $sql, int $resultMode = \MYSQLI_STORE_RESULT): \mysqli_result|bool
{
    if (\async\Registry::$active === []) {
        return $m->query($sql, $resultMode);
    }
    return \async\await(start($m, $sql, $resultMode));
}

/**
 * Send the query now and return an awaitable that resolves to what query() would return.
 * The MySQL protocol allows one query in flight per connection, so a query started while another
 * is running on the same connection is queued and sent when that one completes. Outside
 * async\run() the query completes before start() returns (the awaitable is already settled).
 * A timed-out or cancelled await leaves the query running to completion.
 */
function start(\mysqli $m, string $sql, int $resultMode = \MYSQLI_STORE_RESULT): object
{
    if (\async\Registry::$active === []) {
        $q = new Query();
        $q->settle(true, $m->query($sql, $resultMode));
        return $q;
    }
    $last = Registry::$last ??= new \WeakMap();
    $prev = $last[$m] ?? null;
    if ($prev === null) {
        $q = driver()->start($m, $sql, $resultMode);
    } else {
        $q = \async\go(static function () use ($prev, $m, $sql, $resultMode) {
            try {
                \async\await($prev);
            } catch (\Throwable) {
                // the previous query's failure belongs to its own awaiters
            }
            return \async\await(driver()->start($m, $sql, $resultMode));
        });
    }
    $last[$m] = $q;
    \async\go(static function () use ($last, $m, $q) {     // forget it once settled
        try {
            \async\await($q);
        } catch (\Throwable) {
        }
        if (($last[$m] ?? null) === $q) {
            unset($last[$m]);
        }
    });
    return $q;
}

/**
 * Install the driver. Same semantics as async\setup(): repeating it with a driver of the same
 * class is a no-op, a different class throws \LogicException.
 */
function setup(Driver $driver): void
{
    $current = Registry::$driver;
    if ($current === null) {
        Registry::$driver = $driver;
    } elseif (\get_class($current) !== \get_class($driver)) {
        throw new \LogicException(\sprintf(
            'async\mysqli driver %s is already set up; refusing %s.', \get_class($current), \get_class($driver)));
    }
}

/**
 * Offer a driver candidate: a thin wrapper over Registry::$candidates. Adapter packages write
 * Registry::$candidates directly from their Composer `files` autoload, guarded by
 * interface_exists(\async\mysqli\Driver::class); applications and later packages may call this.
 *
 * @param callable(): Driver $factory
 */
function register(callable $factory, string $package): void
{
    Registry::$candidates[$package] = $factory;
}

/**
 * The driver in use: setup() wins; else the single registered candidate; else PollDriver.
 * Two or more candidates without setup() throw \async\NotConfiguredException.
 */
function driver(): Driver
{
    if (Registry::$driver !== null) {
        return Registry::$driver;
    }
    $c = Registry::$candidates;
    if (\count($c) > 1) {
        throw new \async\NotConfiguredException('Several async\mysqli drivers are registered ('
            . \implode(', ', \array_keys($c)) . '); call async\mysqli\setup() with the one you want.');
    }
    return Registry::$driver = $c === [] ? new PollDriver() : \current($c)();
}
