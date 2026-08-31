<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Deliverability;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showRegister()
    {
        return view('auth.register');
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        // Registered is what sends the verification link; the host is logged
        // in either way, because nothing they can reach yet needs verifying.
        event(new Registered($user = User::create($data)));

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/dashboard');
    }

    public function showLogin()
    {
        return view('auth.login', ['mailerIsFake' => Deliverability::mailerIsFake()]);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'Those credentials do not match our records.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }

    public function showForgotPassword()
    {
        // A form here would say "check your email" over a mailer that posts
        // nothing. The page says what is actually true instead.
        return view('auth.forgot-password', ['mailerIsFake' => Deliverability::mailerIsFake()]);
    }

    public function sendResetLink(Request $request)
    {
        // The form is already gone when this is true, so only a stale tab or a
        // direct post gets here — and neither may be told an email is coming.
        abort_if(Deliverability::mailerIsFake(), 503, 'Password reset is not configured on this deployment.');

        $request->validate(['email' => ['required', 'email']]);

        // The broker answers differently for an address it knows and one it
        // does not; both are told the same thing, or this form becomes a way of
        // asking which addresses have accounts. It also has its own per-address
        // throttle (config/auth.php), so the route limiter is not the only one.
        Password::sendResetLink($request->only('email'));

        // Named, not back(): this form is the only thing that posts here, and a
        // referer-driven redirect would drop the status somewhere else entirely.
        return redirect('/forgot-password')
            ->with('status', 'If that address has an account, a reset link is on its way.');
    }

    public function showResetPassword(Request $request, string $token)
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            // The same rule registration uses — a reset is not the place to let
            // a weaker password in through the back.
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                // A new remember token as well as a new password: the point of a
                // reset is usually that somebody else has the old one, and a
                // remembered cookie would outlive it.
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            // A spent or forged token and an unknown address are the same
            // answer, for the same reason the request form has one answer.
            throw ValidationException::withMessages([
                'email' => 'That reset link has expired or has already been used.',
            ]);
        }

        // Not logged straight in: whoever holds this link has proved they can
        // read the mailbox, and typing the new password once is what proves the
        // host now knows it.
        return redirect('/login')->with('status', 'Your password is changed. Log in with it.');
    }

    public function showVerifyNotice(Request $request)
    {
        return $request->user()->hasVerifiedEmail()
            ? redirect('/dashboard')
            : view('auth.verify-email', ['email' => $request->user()->email]);
    }

    public function resendVerification(Request $request)
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return redirect('/email/verify')->with('status', 'Sent. Give it a minute, then check spam.');
    }

    public function verifyEmail(Request $request, string $id, string $hash)
    {
        $user = $request->user();

        // The signature proves the link is ours and unexpired; these two prove
        // it is *this* host's. Without them a host could follow a link sent to
        // somebody else's address and have their own marked verified.
        abort_unless(
            hash_equals((string) $user->getKey(), $id)
                && hash_equals(sha1($user->getEmailForVerification()), $hash),
            403,
        );

        // A link sits in a mailbox and gets tapped twice; the second tap is not
        // an error, it is somebody arriving where they already are.
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return redirect()->intended('/dashboard')->with('status', 'Address confirmed.');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
