<?php

use App\Models\User;
use App\Support\Deliverability;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

// The mailer has exactly the shape of the trap that cost this app its first set
// of photos: a default that quietly does nothing, and a UI that says it worked.
// `log` writes the reset link into a file nobody reads while the page says
// "check your email" — so a deployment configured that way is one the app
// refuses to make that promise on, and one the deploy gate stops going live.

it('calls the log mailer fake outside local and testing', function () {
    config(['mail.default' => 'log']);

    expect(Deliverability::mailerIsFake())->toBeFalse(); // this IS the testing environment

    app()->detectEnvironment(fn () => 'production');
    expect(Deliverability::mailerIsFake())->toBeTrue();
});

it('accepts a real transport', function () {
    app()->detectEnvironment(fn () => 'production');

    config(['mail.default' => 'resend']);
    expect(Deliverability::mailerIsFake())->toBeFalse();

    config(['mail.default' => 'array']);
    expect(Deliverability::mailerIsFake())->toBeTrue();
});

it('tells a host password reset is not available rather than promising an email', function () {
    Notification::fake();
    app()->detectEnvironment(fn () => 'production');
    config(['mail.default' => 'log']);

    $this->get('/forgot-password')
        ->assertOk()
        // Static Blade text, so the apostrophe is raw in the HTML: assertSee
        // escapes its needle unless told not to.
        ->assertSee("Password reset isn't set up.", false)
        ->assertDontSee('name="email"', false);

    // ...and the endpoint behind it refuses too, so a stale form cannot lie either.
    // The token is only here because pretending to be production turns CSRF back
    // on for this request, which the testing environment normally waives.
    User::factory()->create(['email' => 'host@example.com']);
    $this->withSession(['_token' => 'a-token'])
        ->post('/forgot-password', ['_token' => 'a-token', 'email' => 'host@example.com'])
        ->assertStatus(503);

    Notification::assertNothingSent();
});

it('keeps the login page from linking at a door that is shut', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['mail.default' => 'log']);

    $this->get('/login')->assertOk()->assertDontSee('href="/forgot-password"', false);
});

// --- The deploy gate ---

it('fails the release when nothing would actually send', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['mail.default' => 'log']);

    $this->artisan('photobooth:check-mail')->assertFailed();
});

it('passes when a transport and a from address are set', function () {
    app()->detectEnvironment(fn () => 'production');
    config([
        'mail.default' => 'resend',
        'mail.from.address' => 'hello@photobooth.example',
        'services.resend.key' => 're_a_key_shaped_thing',
    ]);

    $this->artisan('photobooth:check-mail')
        ->expectsOutputToContain('resend')
        ->assertSuccessful();
});

// `log` was the old trap. The new one is a transport that is named, configured,
// and still cannot send: its SDK was never installed, or its key never made it
// into the environment. Both read as a perfectly real mailer to mailerIsFake(),
// and both fail inside a queue worker where nobody is watching — so the gate has
// to build the transport rather than just read its name. Note the throw is an
// `Error`, not an `Exception`: a missing class and a null key both arrive that
// way, so catching `Exception` would sail straight past this.
it('fails the release when the transport cannot be built', function () {
    app()->detectEnvironment(fn () => 'production');

    Mail::extend('unbuildable', function () {
        throw new \Error('Class "Resend" not found');
    });

    config([
        'mail.default' => 'unbuildable',
        'mail.mailers.unbuildable' => ['transport' => 'unbuildable'],
        'mail.from.address' => 'hello@photobooth.example',
    ]);

    $this->artisan('photobooth:check-mail')->assertFailed();
});

// A blank key is the one that gets past building it: Resend::client('') is a
// perfectly legal call, so the transport constructs and the 401 waits until the
// first real send.
it('fails the release when the Resend key never made it into the environment', function () {
    app()->detectEnvironment(fn () => 'production');
    config([
        'mail.default' => 'resend',
        'mail.from.address' => 'hello@photobooth.example',
        'services.resend.key' => '',
    ]);

    $this->artisan('photobooth:check-mail')->assertFailed();
});

// A default from-address means every reset lands in spam, or bounces, which
// looks exactly like no mailer at all from the host's side.
it('fails on the from address nobody changed', function () {
    app()->detectEnvironment(fn () => 'production');
    config([
        'mail.default' => 'resend',
        'mail.from.address' => 'hello@example.com',
        'services.resend.key' => 're_a_key_shaped_thing',
    ]);

    $this->artisan('photobooth:check-mail')->assertFailed();
});

it('sends a real message when asked to prove it', function () {
    Notification::fake();
    config(['mail.default' => 'array', 'mail.from.address' => 'hello@photobooth.example']);

    $this->artisan('photobooth:check-mail --to=you@example.com')
        ->expectsOutputToContain('you@example.com')
        ->assertSuccessful();
});
