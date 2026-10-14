<?php

namespace async\mysqli;

/**
 * The awaitable returned by PollDriver::start(): a minimal then-able, which async\await()
 * accepts on every adapter. Settles once; then() after settling calls back immediately.
 */
final class Query
{
    private ?bool $ok = null;
    private mixed $value = null;
    /** @var list<array{?callable, ?callable}> */
    private array $waiters = [];

    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): void
    {
        if ($this->ok === null) {
            $this->waiters[] = [$onFulfilled, $onRejected];
        } elseif ($cb = $this->ok ? $onFulfilled : $onRejected) {
            $cb($this->value);
        }
    }

    /** @internal */
    public function settle(bool $ok, mixed $value): void
    {
        $this->ok = $ok;
        $this->value = $value;
        foreach ($this->waiters as $w) {
            if ($cb = $w[$ok ? 0 : 1]) {
                $cb($value);
            }
        }
        $this->waiters = [];
    }
}
