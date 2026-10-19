<?php

namespace fiber\mysqli\Tests;

use PHPUnit\Framework\TestCase;
use function fiber\{run, go, await, await_all};

final class MysqliTest extends TestCase
{
    private static function db(): \mysqli
    {
        return new \mysqli(getenv('MYSQLI_HOST') ?: '127.0.0.1', getenv('MYSQLI_USER') ?: 'root',
            getenv('MYSQLI_PASSWORD') ?: 'test', '', (int) (getenv('MYSQLI_PORT') ?: 13307));
    }

    private static function one(\mysqli_result|bool $r): mixed
    {
        return $r->fetch_row()[0];
    }

    public function testOutsideRunBehavesLikeQuery(): void
    {
        $m = self::db();
        $this->assertSame('1', self::one(\fiber\mysqli\query($m, 'SELECT 1')));
        $q = \fiber\mysqli\start($m, 'SELECT 2');
        $this->assertSame('2', self::one(run(fn() => await($q))));
    }

    public function testQueryInsideRun(): void
    {
        $this->assertSame('hello', run(fn() => self::one(\fiber\mysqli\query(self::db(), "SELECT 'hello'"))));
    }

    public function testOverlap(): void
    {
        $t0 = microtime(true);
        $r = run(function () {
            $dbs = array_map(fn() => self::db(), range(1, 10));
            $t0 = microtime(true);
            $r = await_all(array_map(fn($m) => go(fn() => self::one(\fiber\mysqli\query($m, 'SELECT SLEEP(0.2)'))), $dbs));
            return [$r, microtime(true) - $t0];
        });
        [$r, $elapsed] = $r;
        $this->assertSame(array_fill(0, 10, '0'), $r);
        $this->assertLessThan(1.5, $elapsed, "10 x 200 ms took {$elapsed}s (serial is 2.0 s)");
    }

    public function testOtherTasksRunWhileWaiting(): void
    {
        $ticks = run(function () {
            $m = self::db();
            $ticks = 0;
            $t = go(function () use (&$ticks) {
                for ($i = 0; $i < 5; $i++) {
                    \fiber\sleep(0.02);
                    $ticks++;
                }
            });
            \fiber\mysqli\query($m, 'SELECT SLEEP(0.2)');
            await($t);
            return $ticks;
        });
        $this->assertSame(5, $ticks);
    }

    public function testStartIsEagerAndRepeatableAwait(): void
    {
        [$a, $b, $elapsed] = run(function () {
            $m = self::db();
            $q = \fiber\mysqli\start($m, 'SELECT SLEEP(0.2), 42');
            \fiber\sleep(0.2);                      // the query runs meanwhile
            $t0 = microtime(true);
            $r = await($q);
            $a = $r->fetch_row()[1];
            $r->data_seek(0);
            $b = await($q)->fetch_row()[1];
            return [$a, $b, microtime(true) - $t0];
        });
        $this->assertSame(['42', '42'], [$a, $b]);
        $this->assertLessThan(0.15, $elapsed);
    }

    public function testSyntaxErrorReportsThroughNormalChannelAndConnectionSurvives(): void
    {
        [$r, $errno, $after] = run(function () {
            $m = self::db();
            mysqli_report(MYSQLI_REPORT_OFF);
            try {
                $r = \fiber\mysqli\query($m, 'SELEKT 1');
                $errno = $m->errno;
            } finally {
                mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            }
            return [$r, $errno, self::one(\fiber\mysqli\query($m, 'SELECT 3'))];
        });
        $this->assertFalse($r);
        $this->assertSame(1064, $errno);
        $this->assertSame('3', $after);
    }

    public function testSyntaxErrorThrowsInExceptionMode(): void
    {
        [$code, $after] = run(function () {
            $m = self::db();
            try {
                \fiber\mysqli\query($m, 'SELEKT 1');
                $code = null;
            } catch (\mysqli_sql_exception $e) {
                $code = $e->getCode();
            }
            return [$code, self::one(\fiber\mysqli\query($m, 'SELECT 4'))];
        });
        $this->assertSame(1064, $code);
        $this->assertSame('4', $after);
    }

    public function testQueriesOnOneConnectionQueue(): void
    {
        $r = run(function () {
            $m = self::db();
            $a = \fiber\mysqli\start($m, 'SELECT SLEEP(0.1), 1');
            $b = \fiber\mysqli\start($m, 'SELECT 2');
            $c = \fiber\mysqli\start($m, 'SELEKT');
            $d = \fiber\mysqli\start($m, 'SELECT 4');
            try {
                await($c);
            } catch (\mysqli_sql_exception) {
            }
            return [await($a)->fetch_row()[1], self::one(await($b)), self::one(await($d))];
        });
        $this->assertSame(['1', '2', '4'], $r);
    }

    public function testTimedOutAwaitLeavesQueryRunning(): void
    {
        [$timedOut, $late, $next] = run(function () {
            $m = self::db();
            $q = \fiber\mysqli\start($m, 'SELECT SLEEP(0.2), 5');
            try {
                await($q, 0.05);
                $timedOut = false;
            } catch (\fiber\TimeoutException) {
                $timedOut = true;
            }
            $next = self::one(\fiber\mysqli\query($m, 'SELECT 6'));   // queued behind the first
            return [$timedOut, await($q)->fetch_row()[1], $next];
        });
        $this->assertTrue($timedOut);
        $this->assertSame(['5', '6'], [$late, $next]);
    }
}
