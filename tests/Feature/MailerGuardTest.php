<?php

use App\Models\User;
use App\Support\Deliverability;
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

    config(['mail.default' => 'ses']);
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
        'mail.default' => 'ses',
        'mail.from.address' => 'hello@photobooth.example',
    ]);

    $this->artisan('photobooth:check-mail')
        ->expectsOutputToContain('ses')
        ->assertSuccessful();
});

// A default from-address means every reset lands in spam, or bounces, which
// looks exactly like no mailer at all from the host's side.
it('fails on the from address nobody changed', function () {
    app()->detectEnvironment(fn () => 'production');
    config([
        'mail.default' => 'ses',
        'mail.from.address' => 'hello@example.com',
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
