<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * strategies — global config per trading strategy (admin toggle).
 * Rows are created lazily on first toggle; a missing row means enabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategies', function (Blueprint $table) {
            $table->id();
            $table->string('strategy_key', 191)->unique()->comment('Raw strategy name from binance_pastpositions.strategy');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategies');
    }
};
