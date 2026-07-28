<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * crypto_wallets — a user's saved crypto payout wallets (Settings → payment
 * methods). is_main marks the preferred wallet, which pre-fills the admin
 * affiliate release screen. One main per user is enforced in code (set-main
 * clears siblings inside a transaction), not by constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crypto_wallets', function (Blueprint $table) {
            $table->id();
            $table->string('uni_id', 36)->index('idx_crypto_wallets_uni_id');
            $table->string('network', 50)->comment('e.g. TRC20, ERC20, BEP20');
            $table->string('address', 255);
            $table->string('name', 100)->comment('User label, e.g. "Main USDT wallet"');
            $table->boolean('is_main')->default(false);
            $table->timestamps();

            $table->foreign('uni_id', 'fk_crypto_wallets_uni_id')
                ->references('uni_id')->on('user_credentials')
                ->cascadeOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crypto_wallets');
    }
};
