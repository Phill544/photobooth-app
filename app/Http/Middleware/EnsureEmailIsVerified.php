<?php

namespace App\Http\Middleware;

use App\Support\Deliverability;
use Closure;
use Illuminate\Http\Request;

// Laravel's own `verified` middleware, with one condition on top: it steps
// aside when the app has no mailer. Requiring a link that nothing can send is a
// locked door with no key cut for it — and DEPLOY.md is explicit that a failing
// deploy command may not abort the release, so a deployment that reaches
// production with MAIL_MAILER=log is a state this app has to survive rather
// than assume away. Verification is a check on a new host's address, not a
// second password, so lifting it costs nothing when it cannot be satisfied.
class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next)
    {
        if (Deliverability::mailerIsFake() || $request->user()?->hasVerifiedEmail()) {
            return $next($request);
        }

        // guest(), not a plain redirect: it stores where the host was going, so
        // confirming the address drops them on the form they came for rather
        // than back at the dashboard to start again.
        return redirect()->guest('/email/verify');
    }
}
