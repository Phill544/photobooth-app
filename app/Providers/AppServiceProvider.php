<?php

namespace App\Providers;

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
        // One booth session is ~4 uploads plus retries; 30/min per phone is
        // roomy for guests and a lid on anyone hosing a public event code.
        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });
    }
}
