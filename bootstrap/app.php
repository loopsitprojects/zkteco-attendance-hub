<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            \Illuminate\Support\Facades\Route::middleware('throttle:adms')->group(base_path('routes/adms.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['admin' => \App\Http\Middleware\EnsureAdmin::class]);
        $middleware->web(prepend: [\App\Http\Middleware\ForceHttps::class]);
        // Only when a proxy (e.g. Cloudflare) sits in front: list its IPs in TRUSTED_PROXIES.
        // Trusting everyone would let anyone fake their IP and dodge the login lockout.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : explode(',', $proxies));
        }
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
