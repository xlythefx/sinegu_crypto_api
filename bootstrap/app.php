<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Laravel's default sends a guest to route('login'), which this API
        // does not have — so a guest without `Accept: application/json` (curl,
        // a probe, a script) got a 500 "Route [login] not defined" instead of
        // a 401. No redirect on /api/* means the JSON 401 below; anything else
        // goes to the SPA's sign-in page.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : '/auth',
        );

        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
            'staff' => \App\Http\Middleware\EnsureStaff::class,
            'developer' => \App\Http\Middleware\EnsureDeveloper::class,
            // Not 'verified' — that is Laravel's own alias for its link-based flow.
            'email.verified' => \App\Http\Middleware\EnsureEmailVerified::class,
            // A suspended account's token is refused everywhere but sign-out.
            'account.active' => \App\Http\Middleware\EnsureAccountActive::class,
            'engine' => \App\Http\Middleware\VerifyEngineSecret::class,
            // The read-only AI connector's key (Admin → AI Access).
            'ai.key' => \App\Http\Middleware\VerifyAiAccessKey::class,
            'stripe.webhook' => \App\Http\Middleware\VerifyStripeSignature::class,
            'coinsbuy.webhook' => \App\Http\Middleware\VerifyCoinsbuySignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
        // Every reported server error also goes to the developer error bot
        // (Christian's DM, once an hour per place). Logging is unchanged.
        $exceptions->reportable(function (\Throwable $e): void {
            try {
                app(\App\Services\DevBot\DevBotReporter::class)->exception($e);
            } catch (\Throwable) {
            }
        });
    })->create();
