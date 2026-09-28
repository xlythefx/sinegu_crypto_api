<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the `collaborator` role.
 *
 * A collaborator is READ-ONLY staff: the admin dashboard's Overview, User
 * Management (list + user detail) and Strategies, through the `staff`
 * middleware (App\Http\Middleware\EnsureStaff). It is deliberately NOT in
 * EnsureAdmin::ROLES — that list opens every admin write and the staff-only
 * exchanges — and the user-detail payloads drop fee settings and account /
 * key details for it.
 *
 * Same mechanics as the `developer` migration: a raw ALTER on MySQL (widens
 * the enum in place), the schema builder on SQLite, whose CHECK-constraint
 * enum has to be rebuilt.
 */
return new class extends Migration
{
    private const ROLES = ['user', 'admin', 'master', 'developer', 'collaborator'];

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
        // not `admin`: a rollback must never GRANT access the row did not have.
        DB::table('user_credentials')->where('type', 'collaborator')->update(['type' => 'user']);

        $this->setRoles(['user', 'admin', 'master', 'developer']);
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
