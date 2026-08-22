<?php

namespace App\Providers;

use App\Models\Event;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
            $event = $request->route('event');
            $code = $event instanceof Event ? $event->code : $event;

            return Limit::perMinute(60)->by("uploads:{$code}");
        });
    }
}
