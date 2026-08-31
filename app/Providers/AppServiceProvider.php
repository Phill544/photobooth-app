<?php

namespace App\Providers;

use App\Models\Event;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
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
        // The one email this app sends, and it goes to somebody who may not
        // remember signing up — so it says which app it is, in this app's voice,
        // rather than the framework's stock copy. The layout stays Laravel's.
        ResetPassword::toMailUsing(fn ($notifiable, string $token) => (new MailMessage)
            ->subject('Reset your Photobooth password')
            ->greeting('Photobooth')
            ->line('Someone asked to reset the password for the host account on '.$notifiable->getEmailForPasswordReset().'.')
            ->action('Set a new password', url('/reset-password/'.$token.'?email='.urlencode($notifiable->getEmailForPasswordReset())))
            ->line('The link works once, and expires in '.config('auth.passwords.users.expire').' minutes.')
            ->line('If this was not you, nothing has changed and you can ignore this email.')
            ->salutation('— Photobooth'));

        VerifyEmail::toMailUsing(fn ($notifiable, string $url) => (new MailMessage)
            ->subject('Confirm your Photobooth address')
            ->greeting('Photobooth')
            ->line('Confirm this address and you can open your first booth.')
            ->action('Confirm my address', $url)
            ->line('If you did not sign up, ignore this and no account will be used.')
            ->salutation('— Photobooth'));

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
