# fiber-interop/mysqli

Cooperative mysqli queries for [fiber-interop/api](https://github.com/fiber-interop/api): inside
`async\run()` a query suspends only the calling task, on whichever adapter is installed.

```
composer require fiber-interop/mysqli
```

```php
use function async\{run, go, await_all};

$rows = run(fn() => await_all(array_map(
    fn($sql) => go(function () use ($sql) {
        $m = new mysqli('127.0.0.1', 'app', 'secret', 'shop');   // one connection per concurrent query
        return async\mysqli\query($m, $sql)->fetch_all();        // same contract as $m->query()
    }),
    ['SELECT * FROM orders', 'SELECT * FROM customers'],
)));
```

## API

- `async\mysqli\query(\mysqli $m, string $sql, int $resultMode = MYSQLI_STORE_RESULT): \mysqli_result|bool`
  — exactly `$m->query()`'s contract: a `mysqli_result` for result sets, `true` for other statements,
  `false` on failure with `$m->errno`/`$m->error` set, or a `mysqli_sql_exception` thrown when
  `mysqli_report()` asks for exceptions (the default since PHP 8.1). The connection stays usable after
  an error. Outside `async\run()` it is a plain blocking `$m->query()`.
- `async\mysqli\start(...same arguments): object` — sends the query and returns an awaitable
  (repeatable `async\await()`) resolving to what `query()` returns. `query()` is `await(start())`.
- `async\mysqli\Driver` — one method, `start(\mysqli $m, string $sql, int $resultMode): object`.
- `async\mysqli\setup(Driver)`, `async\mysqli\register(callable $factory, string $package)`,
  `async\mysqli\driver()` — same semantics as the api's adapter registry: `setup()` wins; a single
  registered candidate is instantiated lazily; two candidates without `setup()` throw
  `async\NotConfiguredException`; with none, the generic `PollDriver` is used.

Adapters register a native driver from their `register.php` exactly as for
[fiber-interop/curl](https://github.com/fiber-interop/curl): by writing
`async\mysqli\Registry::$candidates[...]` behind `interface_exists(\async\mysqli\Driver::class)` and
their own runtime check.

## One query per connection

The MySQL protocol allows one query in flight per connection. `start()` on a connection that is
still busy does not fail: the query is queued and sent when the previous one completes, so two
tasks sharing a connection run their queries one after the other. For parallelism, use one
connection per concurrent task.

## How it waits: polling, honestly

`PollDriver` sends with `$m->query($sql, MYSQLI_ASYNC)` and one poller task calls
`mysqli_poll(..., 0, 0)` over all in-flight connections, then `$m->reap_async_query()` for the ready
ones. Between rounds it sleeps with `async\sleep()`: 1 ms after a round in which something completed,
backing off ×1.5 toward 10 ms while nothing does. It stops when no query is in flight.

This is polling. mysqli exposes no file descriptor for a connection (mysqlnd has a PHP stream
internally but does not hand it out), so `async\readable()` cannot be used. The php-src fix is small:
a `mysqli_socket()` / stream accessor returning the connection's stream, after which the driver could
wait on the fd like `pg_socket()` allows for PostgreSQL. Native drivers below avoid the polling.

## Native drivers

| Runtime | Driver |
|---|---|
| phasync with the phasync extension loaded | `FiberInterop\Phasync\MysqliDriver` (`go(fn() => $m->query(...))`; the extension hooks PHP streams, which mysqlnd uses) |
| Swoole / OpenSwoole | `FiberInterop\Swoole\MysqliDriver` (same; `SWOOLE_HOOK_TCP`, part of `SWOOLE_HOOK_ALL`, makes mysqlnd's socket cooperative) |
| Revolt, AMPHP, ReactPHP, phasync without the extension | `PollDriver` |

## Limits

- A timed-out or cancelled `await()` does not abort the query; it runs to completion on the server
  and its result is discarded unless awaited again. Later queries on that connection wait for it.
- With `MYSQLI_USE_RESULT` the queue considers the query done when the result is returned, not
  when it is fully fetched: free or drain an unbuffered result before starting the next query on
  that connection, as with blocking mysqli ("Commands out of sync" otherwise).
- Only `query()`. Prepared statements, `multi_query()` and `real_connect()` still block; wrap them
  in your own task or use a native driver, where they are cooperative too.

## Tests

The suite needs a MySQL or MariaDB server; it never shells out to a client. Environment variables
(defaults in parentheses): `MYSQLI_HOST` (127.0.0.1), `MYSQLI_PORT` (13307), `MYSQLI_USER` (root),
`MYSQLI_PASSWORD` (test). For example:

```
docker run -d --name fi-mysql -p 127.0.0.1:13307:3306 -e MYSQL_ROOT_PASSWORD=test mysql:8.4
FI_RUNTIME=amp vendor/bin/phpunit
FI_RUNTIME=phasync FI_DRIVER=native php -d extension=phasync.so vendor/bin/phpunit
FI_RUNTIME=swoole FI_DRIVER=native php -d extension=swoole.so vendor/bin/phpunit
```

`FI_RUNTIME=revolt|amp|react|phasync|swoole` picks the adapter, `FI_DRIVER=generic|native` the driver.
CI runs every runtime against a `mysql:8.4` service container.
