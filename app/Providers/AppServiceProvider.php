<?php

namespace App\Providers;

use App\Services\Payments\PaymentEnvironment;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton so the machine signals are read (and memoised) once per
        // process — every payment path must see the same verdict.
        $this->app->singleton(PaymentEnvironment::class, fn () => new PaymentEnvironment);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Named limiters for the four unauthenticated auth writes; routes/api.php
     * binds them as `throttle:login` and so on.
     *
     * Each pairs a per-IP limit with a per-EMAIL one. The per-IP limit is the
     * old `throttle:N,1` and only ever slowed one machine: an attacker working
     * through a proxy pool answers from a fresh address every few requests
     * and never meets it, which until 2026-10-07 left credential stuffing
     * against one login, and "forgot → five guesses → forgot again" against
     * one reset code, bounded by nothing but patience. The per-email key is
     * what closes that: it counts every request that names the SAME account,
     * whoever sends it and from wherever, so the ceiling is per account per
     * minute rather than per source. Deliberately NOT suffixed with the IP —
     * that would be the per-IP limit again under another name.
     *
     * The price is a one-minute 429 for the real owner while someone hammers
     * their address. Accepted: the account itself is never locked, the window
     * is a minute, and the alternative is an attacker with unlimited guesses.
     *
     * A named limiter hashes `name + key`, so 'login|…' and 'forgot|…' never
     * share a bucket for the same address.
     */
    private function configureRateLimiting(): void
    {
        // Login: 10/min per IP is still generous for a person mistyping;
        // 5/min per account is the same ceiling the per-code attempt caps use.
        // An account here holds exchange API keys — this is the endpoint
        // credential stuffing aims at.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip()),
            Limit::perMinute(5)->by('login|'.$this->emailKey($request)),
        ]);

        // Register: a bot creating accounts has no legitimate rate at all.
        // There is no account to key on yet, so the IP alone.
        RateLimiter::for('register', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
        ]);

        // Forgot SENDS MAIL on every accepted call, and reissuing the code is
        // how an attacker would try to buy more guesses — the per-email limit
        // is what stops the rotation. 2/min is one request and one "it did
        // not arrive" retry; PasswordResetController's 60 s cooldown sends
        // nothing for the second anyway.
        RateLimiter::for('forgot-password', fn (Request $request) => [
            Limit::perMinute(3)->by($request->ip()),
            Limit::perMinute(2)->by('forgot|'.$this->emailKey($request)),
        ]);

        // Reset sits ABOVE its per-code attempt cap
        // (PasswordResetController::MAX_ATTEMPTS, 5) on purpose: the cap is
        // what voids a guessed-at code, and it must be reachable before the
        // throttle hides it. These are the backstop against hammering many
        // accounts' codes from one IP, or one account's from many.
        RateLimiter::for('reset-password', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip()),
            Limit::perMinute(10)->by('reset|'.$this->emailKey($request)),
        ]);
    }

    /**
     * The email a request names, normalised so "Bob@X.com " and "bob@x.com"
     * land in one bucket. '' when absent or not a string: validation refuses
     * that a step later, but the limiter runs first and must not throw.
     */
    private function emailKey(Request $request): string
    {
        $email = $request->input('email');

        return is_string($email) ? strtolower(trim($email)) : '';
    }
}
