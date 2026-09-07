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
use Laravel\Nightwatch\Facades\Nightwatch;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Nightwatch's default resolver sends the signed-in host's name and
        // email address with every recorded request (UserProvider: 'name' =>
        // $user->name, 'username' => $user->email). It is a production
        // dependency, it is live, and its request sampling is 1.0 — so that is
        // every host's name and address going to a third party all day for no
        // benefit we use. The id tells one host's traces from another's, and
        // the provider adds it whatever this returns.
        Nightwatch::user(fn () => []);

        // The one email this app sends, and it goes to somebody who may not
        // remember signing up — so it says which app it is, in this app's voice,
        // rather than the framework's stock copy. The layout stays Laravel's.
        ResetPassword::toMailUsing(fn ($notifiable, string $token) => (new MailMessage)
            ->subject('Reset your Quikbooth password')
            ->greeting('Quikbooth')
            ->line('Someone asked to reset the password for the host account on '.$notifiable->getEmailForPasswordReset().'.')
            // No `?email=` on the link. Nightwatch records full URLs including
            // query strings and offers no way to scrub them, and its sampling
            // rates all default to 1.0 — so every reset click handed the
            // monitoring vendor both halves of the credential at once: the token
            // in the path and the address it is checked against. (Its storage
            // region is Sydney, so this is a third party holding a live
            // credential, not a border crossing.) The token alone opens
            // nothing; resetPassword() requires both. The form has always coped
            // with an absent address — the field is editable and takes focus.
            ->action('Set a new password', url('/reset-password/'.$token))
            ->line('The link works once, and expires in '.config('auth.passwords.users.expire').' minutes.')
            ->line('If this was not you, nothing has changed and you can ignore this email.')
            ->salutation('— Quikbooth'));

        VerifyEmail::toMailUsing(fn ($notifiable, string $url) => (new MailMessage)
            ->subject('Confirm your Quikbooth address')
            ->greeting('Quikbooth')
            ->line('Confirm this address and you can open your first booth.')
            ->action('Confirm my address', $url)
            ->line('If you did not sign up, ignore this and no account will be used.')
            ->salutation('— Quikbooth'));

        // Keyed on the event code, not the IP: every guest at a venue shares
        // one NAT IP, and X-Forwarded-For is client-spoofable behind our
        // trusted proxy — either would make an IP key useless. The code comes
        // from the URL, so it can't be forged, and one event's flood can't
        // starve another's. A session is ~4 uploads, so 60/min is roomy for a
        // busy booth while capping how fast one event's pool can be hosed.
        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute(60)->by('uploads:'.self::eventCode($request));
        });

        // Album PINs are rationed too, but in EventController::unlock() rather
        // than here: a route throttle charges every caller, and once a changed
        // PIN can send a whole room back through that door at once, only the
        // wrong guesses can be allowed to count.
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
