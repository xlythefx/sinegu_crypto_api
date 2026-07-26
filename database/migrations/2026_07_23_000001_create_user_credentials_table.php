<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * user_credentials — ported from the live sinegu_db schema (mother API).
 * PK is the uni_id UUID (no auto-increment id), matching production.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_credentials', function (Blueprint $table) {
            $table->string('uni_id', 36)->primary()->comment('Unique identifier (UUID)');
            $table->string('name')->comment('User full name');
            $table->string('email')->unique('email_unique')->comment('User email address');
            $table->string('password')->comment('Hashed password');

            // Profit-share / commission settings
            $table->decimal('realized_percentage', 5, 2)->default(20.00);
            $table->decimal('unrealized_percentage', 5, 2)->default(6.00);
            $table->decimal('affiliate_percentage', 5, 2)->default(20.00)->comment('Affiliate/referral commission percentage');

            $table->string('course_level', 100)->nullable()->default('first')->comment('User course or progress level');
            $table->string('user_profile', 500)->nullable()->comment('Path/URL to user profile image');
            $table->string('user_banner', 500)->nullable()->comment('Path/URL to user banner image');

            // Password reset
            $table->string('reset_code', 6)->nullable();
            $table->dateTime('reset_code_expires_at')->nullable();
            $table->integer('reset_code_attempts')->default(0);

            $table->enum('status', ['pending', 'active', 'suspended'])->default('pending')->index('status');

            // Email verification
            $table->boolean('email_verified')->default(false)->comment('1 = email verified, 0 = pending');
            $table->string('verification_code', 6)->nullable()->comment('6-digit email verification code');
            $table->dateTime('verification_code_expires_at')->nullable();
            $table->integer('verification_code_attempts')->default(0);

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->dateTime('last_activity')->nullable()->index('idx_last_activity');

            $table->index(['reset_code', 'reset_code_expires_at'], 'idx_reset_code');

            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_credentials');
    }
};
