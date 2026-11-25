<?php

namespace App\Support;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\RateLimiter;
use PDOException;
use Throwable;

/**
 * Laravel's rate limiter, retrying a cache write that lost a deadlock.
 *
 * Every `throttle:` keeps its counters in the cache, which here is the MySQL
 * `cache` table. A landing page opens its public calls (quote strip, order
 * book, track record) at the same moment and they share ONE limiter key per
 * visitor, so when that key's window has expired several PHP workers each
 * delete the stale row and `insert ignore` a fresh one at once. InnoDB's gap
 * and duplicate-key locks turn that into a deadlock now and then, and MySQL
 * answers the loser with 1213 "try restarting transaction" — which Laravel
 * does not do outside DB::transaction, so a visitor got a 500 from the
 * throttle before the controller ever ran.
 *
 * So each limiter call is re-run (up to ATTEMPTS, with a short jittered
 * pause) when — and only when — the failure is a DEADLOCK (`isDeadlock`). Never
 * fail OPEN: after the last attempt the error is thrown as before, because
 * these limiters also guard login and password reset.
 *
 * Re-running a whole `increment` can at worst count one extra hit, if the
 * deadlock struck its final `put` after the counter had already committed.
 * A limiter one hit stricter, once, is the accepted price.
 *
 * Two guards keep the retry honest:
 *  - only the OUTERMOST call retries (`tooManyAttempts` calls `attempts`),
 *    so the attempts never multiply;
 *  - nothing is retried inside an open transaction on the cache's
 *    connection: MySQL has already rolled the whole transaction back, so
 *    re-running one statement would carry on as if it had not.
 */
class RetryingRateLimiter extends RateLimiter
{
    public const ATTEMPTS = 3;

    private int $depth = 0;

    public function tooManyAttempts($key, $maxAttempts)
    {
        return $this->retrying(fn () => parent::tooManyAttempts($key, $maxAttempts));
    }

    public function increment($key, $decaySeconds = 60, $amount = 1)
    {
        return $this->retrying(fn () => parent::increment($key, $decaySeconds, $amount));
    }

    public function attempts($key)
    {
        return $this->retrying(fn () => parent::attempts($key));
    }

    public function resetAttempts($key)
    {
        return $this->retrying(fn () => parent::resetAttempts($key));
    }

    public function clear($key)
    {
        $this->retrying(fn () => parent::clear($key));
    }

    public function availableIn($key)
    {
        return $this->retrying(fn () => parent::availableIn($key));
    }

    private function retrying(callable $callback): mixed
    {
        if ($this->depth > 0) {
            return $callback();
        }

        $this->depth++;

        try {
            for ($attempt = 1; ; $attempt++) {
                try {
                    return $callback();
                } catch (Throwable $e) {
                    if ($attempt >= self::ATTEMPTS
                        || ! self::isDeadlock($e)
                        || $this->insideTransaction()) {
                        throw $e;
                    }

                    usleep(random_int(5_000, 25_000));
                }
            }
        } finally {
            $this->depth--;
        }
    }

    /**
     * A deadlock and nothing else: SQLSTATE 40001 / MySQL 1213, or MySQL's
     * own wording when the driver code is not carried.
     *
     * Not Laravel's DetectsConcurrencyErrors: that also counts "Lock wait
     * timeout exceeded" (1205), which MySQL only says after waiting
     * innodb_lock_wait_timeout (50 s by default). Retrying it three times
     * could hold one request for minutes, far past Cloudflare's 100 s. A
     * deadlock is refused at once, so its retry costs milliseconds.
     */
    public static function isDeadlock(Throwable $e): bool
    {
        if ($e instanceof PDOException
            && ((string) $e->getCode() === '40001' || (int) ($e->errorInfo[1] ?? 0) === 1213)) {
            return true;
        }

        return str_contains($e->getMessage(), 'Deadlock found when trying to get lock');
    }

    private function insideTransaction(): bool
    {
        $store = $this->cache->getStore();

        return $store instanceof DatabaseStore
            && $store->getConnection()->transactionLevel() > 0;
    }
}
