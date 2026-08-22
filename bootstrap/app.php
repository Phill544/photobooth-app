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
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Where auth middleware sends people: guests -> login, logged-in -> dashboard.
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/dashboard');

        // Dev runs behind an HTTPS tunnel (and production behind a load
        // balancer); without this, asset URLs render as http:// inside
        // https pages and phones block them as mixed content.
        $middleware->trustProxies(at: '*');

        // Booth pages sit open for hours at an event; an expired session
        // would 419 every share and lose the guest's strip. CSRF protects
        // authenticated sessions — this endpoint has none: the event code
        // is the only credential, so the token adds nothing here.
        $middleware->validateCsrfTokens(except: ['e/*/photos']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
