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
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
            'staff' => \App\Http\Middleware\EnsureStaff::class,
            'developer' => \App\Http\Middleware\EnsureDeveloper::class,
            // Not 'verified' — that is Laravel's own alias for its link-based flow.
            'email.verified' => \App\Http\Middleware\EnsureEmailVerified::class,
            // A suspended account's token is refused everywhere but sign-out.
            'account.active' => \App\Http\Middleware\EnsureAccountActive::class,
            'engine' => \App\Http\Middleware\VerifyEngineSecret::class,
            'stripe.webhook' => \App\Http\Middleware\VerifyStripeSignature::class,
            'coinsbuy.webhook' => \App\Http\Middleware\VerifyCoinsbuySignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
