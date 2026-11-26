<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 20% flat (owner, 2026-11-26): the fee is 20% of the month's closed profit,
 * and nothing on positions still open when the bill is made on the 1st.
 *
 * Every user on the old standard 6% open-profit rate moves to 0, and a new
 * user starts on 0. Only rows at exactly 6 move, so a rate an admin set by
 * hand to anything else is left alone. On prod on 2026-11-26 every row with
 * a fee was on 6 (two free accounts were already 0 / 0), which is what makes
 * down() exact: it puts 6 back on every row that still has a realized fee
 * and no open-profit rate. Issued invoices keep their own stored figures.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_credentials', function (Blueprint $table) {
            $table->decimal('unrealized_percentage', 5, 2)->default(0.00)->change();
        });

        DB::table('user_credentials')
            ->where('unrealized_percentage', 6.00)
            ->update(['unrealized_percentage' => 0.00]);
    }

    public function down(): void
    {
        Schema::table('user_credentials', function (Blueprint $table) {
            $table->decimal('unrealized_percentage', 5, 2)->default(6.00)->change();
        });

        DB::table('user_credentials')
            ->where('unrealized_percentage', 0.00)
            ->where('realized_percentage', '>', 0)
            ->update(['unrealized_percentage' => 6.00]);
    }
};
