<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * assets — tradable instruments catalog, ported from sinegu_db.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->increments('asset_id');
            $table->string('ticker', 20)->index('idx_ticker');
            $table->string('type', 32)->nullable()->comment('Asset type: stock, crypto, forex, commodity');
            $table->string('broker', 64)->nullable()->comment('Broker/exchange name (e.g. Binance)');
            $table->string('side', 8)->default('ALL')->comment('Allowed trade side: LONG, SHORT, ALL');
            $table->string('asset_image', 500)->nullable()->comment('Path/URL to custom ticker logo');
            $table->decimal('max_increments', 10, 3)->default(10)->comment('Max position size (Binance: decimals allowed)');
            $table->decimal('base_size', 20, 6)->default(0.001);
            $table->boolean('enabled')->default(true)->index('idx_enabled');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
