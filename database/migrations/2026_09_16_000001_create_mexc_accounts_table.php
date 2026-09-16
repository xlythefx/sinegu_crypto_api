<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mexc_accounts — the MEXC twin of binance_accounts, created in its FINAL
 * shape (the columns 2026_07_24 `is_sandbox` and 2026_08_05 `key_*` added to
 * the Binance table later are here from the start).
 *
 * One table per exchange, not an `exchange` column on binance_accounts: the
 * engine's account list is the hottest read in the system and every
 * exchange-specific reader (positions, past positions, transactions, the
 * invoice PnlSource) joins on `api_key` within its own exchange. Sharing a
 * table would make every one of those reads filter on a discriminator it can
 * forget. App\Services\Exchanges\ExchangeSchema is the one place that maps an
 * exchange name to its four tables.
 *
 * `demo` exists for schema parity only — MEXC has NO futures testnet, so a
 * row flagged demo can never be traded (the engine refuses it outright and
 * the connect flow must reject it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mexc_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('api_key', 128)->unique('uq_mexc_accounts_api_key')->comment('MEXC API access key');
            $table->string('uni_id', 36)->nullable()->index('idx_mexc_accounts_uni_id')->comment('User ID from user_credentials (owner)');
            $table->string('secret_key', 128)->comment('MEXC API secret key');
            $table->string('name', 128)->unique('uq_mexc_accounts_name')->comment('Account display name');
            $table->decimal('balance', 20, 8)->nullable()->comment('Wallet balance (equity - unrealized)');
            $table->decimal('unrealized_pnl', 20, 8)->nullable()->comment('unrealized from /api/v1/private/account/asset/USDT');
            $table->decimal('initial_deposit', 20, 8)->nullable()->comment('Initial deposit amount');
            $table->string('currency_type', 16)->default('USDT')->comment('Account currency');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('deleted_at')->nullable()->index('idx_mexc_accounts_deleted_at')->comment('Soft delete; NULL = active');
            $table->boolean('demo')->default(false)->comment('Parity only — MEXC has no futures testnet; never tradeable');
            $table->boolean('enabled')->default(true)->comment('1=enabled, 0=disabled');
            $table->boolean('is_sandbox')->default(false)->index('idx_mexc_accounts_is_sandbox');
            $table->string('key_status', 16)->default('ok');
            $table->string('key_error_code', 16)->nullable();
            $table->string('key_error_reason', 32)->nullable();
            $table->string('key_error_message', 255)->nullable();
            $table->timestamp('key_blocked_at')->nullable();
            $table->timestamp('key_checked_at')->nullable();

            $table->index(['key_status', 'key_blocked_at'], 'mexc_accounts_key_status_index');

            $table->foreign('uni_id', 'fk_mexc_accounts_uni_id')
                ->references('uni_id')->on('user_credentials')
                ->cascadeOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mexc_accounts');
    }
};
