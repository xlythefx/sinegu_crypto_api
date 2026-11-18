<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the `submaster` role (2026-11-18, UserCredential::SUBMASTER): a second
 * house account whose exchange accounts trade ONLY the sub-master webhooks
 * (/binance_submaster_webhook, …), never the main TradingView feed. Trader
 * dashboard only, never billed (invoicing keys on `type = 'user'`).
 *
 * Also widens `trade_logs.feed` from 8 to 16 characters: "submaster" is 9,
 * and MySQL in strict mode refuses the insert instead of truncating it —
 * which would lose every sub-master signal's row.
 *
 * Same mechanics as the tester migration: a raw ALTER on MySQL, the schema
 * builder on SQLite (whose CHECK-constraint enum has to be rebuilt).
 */
return new class extends Migration
{
    private const ROLES = ['user', 'admin', 'master', 'developer', 'collaborator', 'tester', 'submaster'];

    public function up(): void
    {
        if (Schema::hasColumn('user_credentials', 'type')) {
            $this->setRoles(self::ROLES);
        }
        Schema::table('trade_logs', function (Blueprint $table) {
            $table->string('feed', 16)->default('main')
                ->comment('Signal feed: main (TradingView, everyone) | test (testers only) | submaster (sub-masters only)')
                ->change();
        });
    }

    public function down(): void
    {
        // Rows on a value the column can no longer hold would be truncated.
        // `user` never grants access the row did not have; a rolled-back
        // sub-master's accounts join the MAIN feed (and billing).
        if (Schema::hasColumn('user_credentials', 'type')) {
            DB::table('user_credentials')->where('type', 'submaster')->update(['type' => 'user']);
            $this->setRoles(array_values(array_diff(self::ROLES, ['submaster'])));
        }
        DB::table('trade_logs')->where('feed', 'submaster')->update(['feed' => 'test']);
        Schema::table('trade_logs', function (Blueprint $table) {
            $table->string('feed', 8)->default('main')
                ->comment('Signal feed: main (TradingView, everyone) | test (testers only)')
                ->change();
        });
    }

    /** @param  string[]  $roles */
    private function setRoles(array $roles): void
    {
        if (DB::getDriverName() === 'mysql') {
            $list = implode(',', array_map(fn ($r) => "'".$r."'", $roles));

            DB::statement(
                "ALTER TABLE user_credentials
                 MODIFY COLUMN type ENUM({$list})
                 NOT NULL DEFAULT 'user' COMMENT 'Account role'"
            );

            return;
        }

        Schema::table('user_credentials', function (Blueprint $table) use ($roles) {
            $table->enum('type', $roles)->default('user')->comment('Account role')->change();
        });
    }
};
