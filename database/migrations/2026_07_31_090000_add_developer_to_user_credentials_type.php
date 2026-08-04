<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the `developer` role.
 *
 * A developer is staff who may enter the admin portal AND the Database console,
 * and whose invoice payments always run against the payment providers' SANDBOX
 * credentials — so the pay buttons can be exercised on production without money
 * moving. See App\Services\Payments\PaymentEnvironment::forceSandbox().
 *
 * On MySQL this is a raw ALTER: it widens the enum in place, keeping every
 * existing role. SQLite (the test database) has no MODIFY COLUMN and no real
 * enum — it enforces one through a CHECK constraint — so there the column is
 * rebuilt via the schema builder, which is safe because tests always migrate a
 * fresh database.
 */
return new class extends Migration
{
    private const ROLES = ['user', 'admin', 'master', 'developer'];

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
        // hold, or the ALTER truncates those rows to an empty string.
        DB::table('user_credentials')->where('type', 'developer')->update(['type' => 'admin']);

        $this->setRoles(['user', 'admin', 'master']);
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
