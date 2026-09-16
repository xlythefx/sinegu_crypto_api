<?php

namespace App\Services\Exchanges;

use App\Models\BinanceAccount;
use App\Models\ExchangeAccount;
use App\Models\MexcAccount;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Which tables, model and fee rate belong to an exchange name.
 *
 * The engine addresses the API as /engine/{exchange}/…, and every write it
 * makes lands in that exchange's OWN four tables (accounts, positions,
 * pastpositions, transactions). This class is the single place that mapping
 * exists, so the engine controllers, TradingFee and FeeRebase can be written
 * once and never carry a `binance_` literal.
 *
 * `supported()` is also the gate GuardsEngineExchange applies: a name in the
 * route's whereIn but absent here (bybit, today) answers 400
 * EXCHANGE_NOT_SUPPORTED. Adding an exchange is one REGISTRY entry plus its
 * migrations — nothing in the controllers changes.
 */
final class ExchangeSchema
{
    /**
     * @var array<string, array{
     *     account: class-string<ExchangeAccount>,
     *     positions: string, past_positions: string, transactions: string,
     *     broker: string, fee_config: string, fee_default: float
     * }>
     */
    private const REGISTRY = [
        'binance' => [
            'account' => BinanceAccount::class,
            'positions' => 'binance_positions',
            'past_positions' => 'binance_pastpositions',
            'transactions' => 'binance_transactions',
            'broker' => 'Binance',
            'fee_config' => 'services.binance.taker_fee_rate',
            'fee_default' => 0.0005,
        ],
        'mexc' => [
            'account' => MexcAccount::class,
            'positions' => 'mexc_positions',
            'past_positions' => 'mexc_pastpositions',
            'transactions' => 'mexc_transactions',
            'broker' => 'MEXC',
            'fee_config' => 'services.mexc.taker_fee_rate',
            'fee_default' => 0.0002,
        ],
    ];

    public readonly string $exchange;

    /** @var class-string<ExchangeAccount> */
    public readonly string $accountModel;

    public readonly string $accountsTable;

    public readonly string $positions;

    public readonly string $pastPositions;

    public readonly string $transactions;

    /** The `assets.broker` label this exchange's asset rows carry. */
    public readonly string $brokerLabel;

    private readonly string $feeConfig;

    private readonly float $feeDefault;

    private function __construct(string $exchange, array $entry)
    {
        $this->exchange = $exchange;
        $this->accountModel = $entry['account'];
        $this->accountsTable = (new $entry['account'])->getTable();
        $this->positions = $entry['positions'];
        $this->pastPositions = $entry['past_positions'];
        $this->transactions = $entry['transactions'];
        $this->brokerLabel = $entry['broker'];
        $this->feeConfig = $entry['fee_config'];
        $this->feeDefault = $entry['fee_default'];
    }

    /** @return list<string> */
    public static function supported(): array
    {
        return array_keys(self::REGISTRY);
    }

    public static function isSupported(string $exchange): bool
    {
        return array_key_exists($exchange, self::REGISTRY);
    }

    public static function for(string $exchange): self
    {
        if (! self::isSupported($exchange)) {
            throw new InvalidArgumentException("Exchange '{$exchange}' is not wired to the engine.");
        }

        return new self($exchange, self::REGISTRY[$exchange]);
    }

    /**
     * Taker commission per side, as a fraction. The engine places market orders
     * only, so taker is the one rate that applies on every exchange.
     */
    public function takerFeeRate(): float
    {
        return (float) config($this->feeConfig, $this->feeDefault);
    }

    /** A fresh query on this exchange's account model (soft-deleted excluded). */
    public function accountQuery(): Builder
    {
        return ($this->accountModel)::query();
    }
}
