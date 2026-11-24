<?php

namespace Tests\Feature;

use App\Support\RetryingRateLimiter;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * Every throttle keeps its counters in the cache, which on the box is the
 * MySQL `cache` table. Parallel public calls from one visitor share a limiter
 * key, and when its window expires their `insert ignore`s can deadlock
 * (1213). Before RetryingRateLimiter that reached the visitor as a 500 from
 * the throttle, before the controller ran.
 *
 * No RefreshDatabase on purpose: it wraps each test in a transaction, which
 * would make the "never retry inside a transaction" guard pass for free.
 */
class RetryingRateLimiterTest extends TestCase
{
    public static function deadlock(): QueryException
    {
        return new QueryException(
            'mysql',
            'insert ignore into `cache` (`key`, `value`, `expiration`) values (?, ?, ?)',
            ['test-key:timer', 'i:1;', 1],
            new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'),
        );
    }

    /** An array store whose first `$failures` writes lose a deadlock. */
    private static function flakyStore(int $failures, ?\Throwable $error = null): ArrayStore
    {
        return new class($failures, $error ?? self::deadlock()) extends ArrayStore
        {
            public int $writes = 0;

            public function __construct(private int $failures, private \Throwable $error)
            {
                parent::__construct();
            }

            public function put($key, $value, $seconds)
            {
                $this->writes++;
                if ($this->failures-- > 0) {
                    throw $this->error;
                }

                return parent::put($key, $value, $seconds);
            }
        };
    }

    public function test_the_app_hands_out_the_retrying_limiter_with_the_named_limiters_on_it(): void
    {
        $this->assertInstanceOf(RetryingRateLimiter::class, app(RateLimiter::class));
        $this->assertSame(app(RateLimiter::class), RateLimiterFacade::getFacadeRoot());
        // The named limiters are registered in boot, on the same instance.
        $this->assertNotNull(RateLimiterFacade::limiter('login'));
    }

    public function test_a_deadlocked_cache_write_is_retried_and_the_hit_counts_once(): void
    {
        $store = self::flakyStore(failures: 1);
        $limiter = new RetryingRateLimiter(new Repository($store));

        $this->assertSame(1, $limiter->hit('visitor'));
        $this->assertSame(1, $limiter->attempts('visitor'));
        $this->assertFalse($limiter->tooManyAttempts('visitor', 2));
    }

    public function test_it_gives_up_after_the_last_attempt_and_never_fails_open(): void
    {
        $store = self::flakyStore(failures: 99);
        $limiter = new RetryingRateLimiter(new Repository($store));

        try {
            $limiter->hit('visitor');
            $this->fail('A deadlock on every attempt must still be thrown.');
        } catch (QueryException) {
            $this->assertSame(RetryingRateLimiter::ATTEMPTS, $store->writes);
        }
    }

    public function test_an_error_that_is_not_a_deadlock_is_thrown_at_once(): void
    {
        $store = self::flakyStore(failures: 1, error: new RuntimeException('disk full'));
        $limiter = new RetryingRateLimiter(new Repository($store));

        try {
            $limiter->hit('visitor');
            $this->fail('A non-concurrency error must not be retried.');
        } catch (RuntimeException) {
            $this->assertSame(1, $store->writes);
        }
    }

    /**
     * MySQL rolls the WHOLE transaction back on a deadlock, so re-running one
     * statement inside it would carry on as if nothing had been lost.
     */
    public function test_it_does_not_retry_inside_a_transaction_on_the_cache_connection(): void
    {
        $store = new class(DB::connection(), 'cache') extends DatabaseStore
        {
            public int $writes = 0;

            public function add($key, $value, $seconds)
            {
                $this->writes++;
                throw RetryingRateLimiterTest::deadlock();
            }
        };
        $limiter = new RetryingRateLimiter(new Repository($store));

        // Outside a transaction: retried, then thrown.
        try {
            $limiter->hit('visitor');
        } catch (QueryException) {
        }
        $this->assertSame(RetryingRateLimiter::ATTEMPTS, $store->writes);

        // Inside one: thrown on the first failure, for the transaction to handle.
        $store->writes = 0;
        try {
            DB::transaction(fn () => $limiter->hit('visitor'));
        } catch (QueryException) {
        }
        $this->assertSame(1, $store->writes);
    }

    /** The reported case: GET /api/public/market-ticker 500'd from its throttle. */
    public function test_a_public_call_whose_throttle_deadlocks_once_still_answers(): void
    {
        Cache::flush();
        config([
            'services.market.symbols' => ['BTCUSDT'],
            'services.market.funding_symbol' => 'BTCUSDT',
            'services.market.base_url' => 'https://fapi.binance.com',
        ]);
        Http::fake([
            '*ticker/24hr*' => Http::response([
                'symbol' => 'BTCUSDT',
                'lastPrice' => '68412.50',
                'priceChangePercent' => '1.840',
            ]),
            '*premiumIndex*' => Http::response([
                'lastFundingRate' => '0.000100',
                'nextFundingTime' => 1790265600000,
            ]),
        ]);

        $store = self::flakyStore(failures: 1);
        $this->app->instance(RateLimiter::class, new RetryingRateLimiter(new Repository($store)));

        $this->getJson('/api/public/market-ticker')
            ->assertOk()
            ->assertHeader('X-RateLimit-Remaining', '59');
        $this->assertGreaterThan(1, $store->writes);
    }
}
