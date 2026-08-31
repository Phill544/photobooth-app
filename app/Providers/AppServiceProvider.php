<?php

namespace App\Providers;

use App\Models\Event;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Keyed on the event code, not the IP: every guest at a venue shares
        // one NAT IP, and X-Forwarded-For is client-spoofable behind our
        // trusted proxy — either would make an IP key useless. The code comes
        // from the URL, so it can't be forged, and one event's flood can't
        // starve another's. A session is ~4 uploads, so 60/min is roomy for a
        // busy booth while capping how fast one event's pool can be hosed.
        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute(60)->by('uploads:'.self::eventCode($request));
        });

        // Guessing at an album PIN, keyed on the code for the same reason: a
        // venue is one NAT address, so an IP key throttles the room rather than
        // the attacker. Twenty a minute is far more than a real album ever
        // needs — every guest types the PIN once — and it caps a brute force at
        // a rate no free-text PIN falls to. The trade is that one attacker can
        // hold an album's guests out for a minute at a time, which is the same
        // bargain the upload limiter above already makes.
        RateLimiter::for('album-pin', function (Request $request) {
            return Limit::perMinute(20)->by('album-pin:'.self::eventCode($request));
        });
    }

    // The code out of the URL. Upper-cased, because a limiter runs BEFORE route
    // model binding — so what arrives here is the raw URL segment in whatever
    // case the caller typed, while Event::resolveRouteBindingQuery() will open
    // the same album for every one of them. Un-normalised, /e/party2 and
    // /e/PARTY2 are two budgets against one album, and a six-letter code is
    // dozens: the PIN limiter would promise twenty guesses a minute and hand
    // out hundreds.
    private static function eventCode(Request $request): string
    {
        $event = $request->route('event');

        return Str::upper($event instanceof Event ? $event->code : (string) $event);
    }
}
