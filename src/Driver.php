<?php

namespace fiber\mysqli;

/**
 * Runs queries for fiber\mysqli\start().
 *
 * start() must send the query before it returns and give back something fiber\await() accepts
 * (repeatable, by several awaiters) that resolves to what $m->query($sql, $resultMode) would
 * return, or rejects with what it would throw, leaving $m->errno/$m->error set as query() would.
 * The caller guarantees one query at a time per connection.
 */
interface Driver
{
    public function start(\mysqli $m, string $sql, int $resultMode): object;
}
