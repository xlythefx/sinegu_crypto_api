<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One saved row per user, per scope, per trading day: that day's P&L and its
 * percentage of the balance the day started with — the figure on the P&L
 * calendar cell. The Date Range card ADDS these up for its Period Return, so
 * a window of a +1% day and a +4% day reads +5% (owner's team, 2026-10-05).
 *
 * `scope` is 'all' or one exchange, because a day's percentage depends on
 * which accounts' capital it is measured against — the same pill the
 * analytics page and the calendar carry.
 *
 * Rows are derived and rewritten whenever their day changes (a late close,
 * a fee receipt, an admin correction), so the saved figure always matches
 * the trades beneath it. Nothing else depends on a row surviving.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_returns', function (Blueprint $table) {
            $table->id();
            $table->string('uni_id', 36);
            $table->string('scope', 16)->comment("'all' or an exchange key");
            $table->date('day')->comment('UTC calendar day, like every P&L day bucket');
            $table->decimal('start_balance', 18, 2)->nullable()
                ->comment('Capital the day started with; null = none on record');
            $table->decimal('pnl', 18, 2)->comment('Realized P&L after fees');
            $table->decimal('pnl_gross', 18, 2)->comment('Realized P&L before fees');
            $table->decimal('pct', 12, 2)->nullable()->comment('pnl / start_balance × 100, as the calendar shows it');
            $table->decimal('pct_gross', 12, 2)->nullable();
            $table->unsignedInteger('trades')->default(0);
            $table->timestamps();

            $table->unique(['uni_id', 'scope', 'day'], 'uq_daily_returns_user_scope_day');
            $table->foreign('uni_id', 'fk_daily_returns_uni_id')
                ->references('uni_id')->on('user_credentials')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_returns');
    }
};
