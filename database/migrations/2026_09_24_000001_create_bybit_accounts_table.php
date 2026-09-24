<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * bybit_accounts — the Bybit twin of binance_accounts / mexc_accounts, created
 * in its FINAL shape (the columns added to the Binance table over time —
 * 2026_07_24 `is_sandbox`, 2026_08_05 `key_*` — are here from the start).
 *
 * One table per exchange, not an `exchange` column on binance_accounts: the
 * engine's account list is the hottest read in the system and every
 * exchange-specific reader (positions, past positions, transactions, the
 * invoice PnlSource) joins on `api_key` within its own exchange. Sharing a
 * table would make every one of those reads filter on a discriminator it can
 * forget. App\Services\Exchanges\ExchangeSchema is the one place that maps an
 * exchange name to its four tables.
 *
 * `demo` routes the engine to Bybit **Demo Trading** (api-demo.bybit.com), not
 * to testnet.bybit.com. The two are different products: demo is reached from
 * the ordinary bybit.com login, but its API keys are minted in the Demo
 * Trading UI and are NOT interchangeable with live keys — a live key on the
 * demo host is refused, and a demo key on the live host is refused. That is
 * the same "the key set decides the host" rule the connect wizard's mode step
 * exists to state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bybit_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('api_key', 128)->unique('uq_bybit_accounts_api_key')->comment('Bybit API key');
            $table->string('uni_id', 36)->nullable()->index('idx_bybit_accounts_uni_id')->comment('User ID from user_credentials (owner)');
            $table->string('secret_key', 128)->comment('Bybit API secret');
            $table->string('name', 128)->unique('uq_bybit_accounts_name')->comment('Account display name');
            $table->decimal('balance', 20, 8)->nullable()->comment('USDT walletBalance from /v5/account/wallet-balance (excludes unrealized)');
            $table->decimal('unrealized_pnl', 20, 8)->nullable()->comment('USDT unrealisedPnl from /v5/account/wallet-balance');
            $table->decimal('initial_deposit', 20, 8)->nullable()->comment('Initial deposit amount');
            $table->string('currency_type', 16)->default('USDT')->comment('Account currency');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('deleted_at')->nullable()->index('idx_bybit_accounts_deleted_at')->comment('Soft delete; NULL = active');
            $table->boolean('demo')->default(false)->comment('1 = Bybit Demo Trading (api-demo.bybit.com); keys are NOT the live ones');
            $table->boolean('enabled')->default(true)->comment('1=enabled, 0=disabled');
            $table->boolean('is_sandbox')->default(false)->index('idx_bybit_accounts_is_sandbox');
            $table->string('key_status', 16)->default('ok');
            $table->string('key_error_code', 16)->nullable();
            $table->string('key_error_reason', 32)->nullable();
            $table->string('key_error_message', 255)->nullable();
            $table->timestamp('key_blocked_at')->nullable();
            $table->timestamp('key_checked_at')->nullable();

            $table->index(['key_status', 'key_blocked_at'], 'bybit_accounts_key_status_index');

            $table->foreign('uni_id', 'fk_bybit_accounts_uni_id')
                ->references('uni_id')->on('user_credentials')
                ->cascadeOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bybit_accounts');
    }
};
