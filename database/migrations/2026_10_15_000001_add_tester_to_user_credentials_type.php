<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the `tester` role.
 *
 * A tester is an INTERNAL test account — like a developer account, but with
 * no admin access at all. Its exchange accounts receive signals ONLY from the
 * engine's separate test webhooks (/binance_test_webhook, /mexc_test_webhook,
 * /bybit_test_webhook), never from the main TradingView feed: the engine
 * fans a signal out by the `feed` the accounts endpoint derives from this
 * column (EngineController::accounts). It is not `type = 'user'`, so monthly
 * invoicing and every customer figure skip it — on purpose.
 *
 * Same mechanics as the `collaborator` migration: a raw ALTER on MySQL
 * (widens the enum in place), the schema builder on SQLite, whose
 * CHECK-constraint enum has to be rebuilt.
 */
return new class extends Migration
{
    private const ROLES = ['user', 'admin', 'master', 'developer', 'collaborator', 'tester'];

    public function up(): void
    {
        if (! Schema::hasColumn('user_credentials', 'type')) {
            return;
        }

        $this->setRoles(self::ROLES);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('user_credentials', 'type')) {
            return;
        }

        // Nothing may be left pointing at a value the column can no longer
        // hold, or the ALTER truncates those rows to an empty string. `user`,
        // the same choice the collaborator rollback makes: a rollback must
        // never GRANT access the row did not have. The consequence to know:
        // a rolled-back tester's accounts join the MAIN feed (and billing).
        DB::table('user_credentials')->where('type', 'tester')->update(['type' => 'user']);

        $this->setRoles(['user', 'admin', 'master', 'developer', 'collaborator']);
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
