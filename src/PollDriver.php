<?php

namespace fiber\mysqli;

/**
 * The generic driver: sends with MYSQLI_ASYNC and reaps with reap_async_query(), polled by one
 * fiber\go() task per process.
 *
 * mysqli exposes no socket, so fiber\readable() cannot be used; the poller calls mysqli_poll()
 * with a zero timeout and sleeps between rounds, 1 ms after activity backing off to 10 ms. A
 * mysqli_socket() (or stream) accessor in php-src would let this wait on the fd instead.
 */
final class PollDriver implements Driver
{
    /** @var array<int, array{\mysqli, Query}> */
    private array $queries = [];
    private bool $polling = false;

    public function start(\mysqli $m, string $sql, int $resultMode): object
    {
        $q = new Query();
        if (!$m->query($sql, $resultMode | \MYSQLI_ASYNC)) {
            $q->settle(true, false);    // not sent; $m->error is set (or query() threw)
            return $q;
        }
        $this->queries[\spl_object_id($m)] = [$m, $q];
        if (!$this->polling) {
            $this->polling = true;
            \fiber\go($this->poll(...));
        }
        return $q;
    }

    private function poll(): void
    {
        $interval = 0.001;
        try {
            while ($this->queries !== []) {
                \fiber\sleep($interval);
                $interval = $this->collect() ? 0.001 : \min(0.010, $interval * 1.5);
            }
        } finally {
            $this->polling = false;
        }
    }

    /** Settle finished queries; returns whether any finished. */
    private function collect(): bool
    {
        $read = $error = $reject = \array_column($this->queries, 0);
        if (!\mysqli_poll($read, $error, $reject, 0, 0) && $error === [] && $reject === []) {
            return false;
        }
        foreach ([...$read, ...$error, ...$reject] as $m) {
            $id = \spl_object_id($m);
            if (!isset($this->queries[$id])) {
                continue;
            }
            [, $q] = $this->queries[$id];
            unset($this->queries[$id]);
            try {
                $q->settle(true, $m->reap_async_query());
            } catch (\Throwable $e) {
                $q->settle(false, $e);
            }
        }
        return true;
    }
}
