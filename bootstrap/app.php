<?php

use App\Http\Middleware\EnsureEmailIsVerified;
use App\Models\Event;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Serving a photo needs its route bindings and nothing else — no
        // session, no CSRF, no cookies. An album asks for dozens at once.
        then: fn () => Route::middleware(SubstituteBindings::class)
            ->group(base_path('routes/images.php')),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // A password change ends every session that account had open, not just
        // the remember cookie. The usual reason for a reset is that somebody
        // else has the old password — and that somebody is typically already
        // signed in, where the session guard would otherwise keep
        // re-authenticating them from the user id it holds and never look at
        // the hash again. This is what makes the reset actually revoke.
        $middleware->authenticateSessions();

        // Ours, not the framework's: it steps aside when there is no mailer to
        // send a verification link with. See the class for why.
        $middleware->alias(['verified' => EnsureEmailIsVerified::class]);

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

        // A code that opens no booth is the 404 this app will serve most often —
        // it gets read off a sign and typed by hand. Pass the code that was
        // tried to errors/404.blade.php so it can say so and offer the form
        // again. Every other 404 (including a missing photo under a real code)
        // falls through to the same view without a code to blame.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            $missing = $e->getPrevious();

            if ($request->expectsJson()
                || ! $missing instanceof ModelNotFoundException
                || $missing->getModel() !== Event::class) {
                return null;
            }

            return response()->view('errors.404', ['code' => Str::upper($request->segment(2))], 404);
        });
    })->create();
