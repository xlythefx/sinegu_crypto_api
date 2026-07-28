<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * bank_wire_accounts — a user's saved bank accounts for wire payouts
 * (Settings → payment methods). Detail fields are nullable strings: banks vary
 * wildly (IBAN vs account+routing), so the form collects what applies and the
 * admin release screen shows whatever is on file. is_main mirrors
 * crypto_wallets.is_main (one preferred account per user, enforced in code).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_wire_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('uni_id', 36)->index('idx_bank_wire_accounts_uni_id');
            $table->string('label', 100)->comment('User label, e.g. "Chase personal"');
            $table->string('account_holder', 255)->nullable();
            $table->string('bank_name', 255)->nullable();
            $table->string('account_number', 100)->nullable();
            $table->string('routing_number', 100)->nullable();
            $table->string('iban', 100)->nullable();
            $table->string('swift_bic', 50)->nullable();
            $table->string('account_type', 50)->nullable()->comment('checking / savings / business');
            $table->string('bank_address', 500)->nullable();
            $table->string('currency', 10)->default('USD');
            $table->boolean('is_main')->default(false);
            $table->timestamps();

            $table->foreign('uni_id', 'fk_bank_wire_accounts_uni_id')
                ->references('uni_id')->on('user_credentials')
                ->cascadeOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_wire_accounts');
    }
};
