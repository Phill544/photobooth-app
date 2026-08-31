<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

beforeEach(function () {
    // Local and testing are exempt from the mailer guard — the log mailer is the
    // right one here — so the pages under test behave as a configured deploy.
    $this->host = User::factory()->create(['email' => 'host@example.com']);
});

it('offers a way back in from the login page', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Forgot')
        ->assertSee('href="/forgot-password"', false);
});

it('sends a reset link to an address we know', function () {
    Notification::fake();

    $this->post('/forgot-password', ['email' => 'host@example.com'])
        ->assertRedirect('/forgot-password');

    Notification::assertSentTo($this->host, ResetPassword::class);
});

// Two different answers here would turn this form into a way of asking which
// addresses have accounts.
it('says the same thing about an address we do not know', function () {
    Notification::fake();

    $known = $this->post('/forgot-password', ['email' => 'host@example.com']);
    $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.com']);

    expect($unknown->getSession()->get('status'))->toBe($known->getSession()->get('status'));
    Notification::assertSentTimes(ResetPassword::class, 1);
});

it('still asks for something that looks like an address', function () {
    $this->post('/forgot-password', ['email' => 'not-an-address'])->assertInvalid(['email']);
});

it('opens a form carrying the token from the link', function () {
    $token = Password::createToken($this->host);

    $this->get("/reset-password/{$token}")
        ->assertOk()
        ->assertSee('value="'.$token.'"', false)
        ->assertSee('name="password"', false);
});

it('sets the new password and sends the host to log in with it', function () {
    $token = Password::createToken($this->host);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => 'host@example.com',
        'password' => 'a-brand-new-one',
        'password_confirmation' => 'a-brand-new-one',
    ])->assertRedirect('/login');

    $this->post('/login', ['email' => 'host@example.com', 'password' => 'a-brand-new-one'])
        ->assertRedirect('/dashboard');
});

// A reset that leaves the old password working is not a reset — it is a second
// password, and the reason someone reset is usually that the first one leaked.
it('stops the old password working', function () {
    $token = Password::createToken($this->host);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => 'host@example.com',
        'password' => 'a-brand-new-one',
        'password_confirmation' => 'a-brand-new-one',
    ]);

    $this->post('/login', ['email' => 'host@example.com', 'password' => 'password'])
        ->assertInvalid(['email']);
});

// The reason somebody resets is usually that another person has the old
// password — and that person is typically already signed in. Rolling the
// remember token only revokes the cookie they never used; the live session kept
// re-authenticating from the user id in it and never re-checked the hash.
//
// Split from the reset endpoint deliberately: the endpoint sits behind `guest`,
// so one test client cannot both hold the intruder's session and complete the
// form. What is asserted here is the guarantee — a changed password ends every
// session — and the test above already pins that the reset changes it.
it('ends a session that was already signed in when the password changes', function () {
    $this->post('/login', ['email' => 'host@example.com', 'password' => 'password'])
        ->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertOk();

    $this->host->forceFill(['password' => 'a-brand-new-one'])->save();

    // Every request is its own process in production; in a test the auth guard
    // survives between them and would hand back the user it already resolved,
    // stale hash and all. This is that process boundary, not a workaround.
    $this->app['auth']->forgetGuards();

    $this->get('/dashboard')->assertRedirect('/login');
});

// The unnamed throttle keys on the IP alone — not the route, not the limit — so
// every guest auth POST shared one counter and the smallest cap on it won. Six
// failed logins used to 429 the one form that would let a host back in.
it('does not spend the login budget on the way back in', function () {
    foreach (range(1, 6) as $attempt) {
        $this->post('/login', ['email' => 'host@example.com', 'password' => 'wrong'])
            ->assertStatus(302);
    }

    $this->post('/forgot-password', ['email' => 'host@example.com'])->assertStatus(302);
    $this->post('/reset-password', ['token' => 'x', 'email' => 'host@example.com'])->assertStatus(302);
});

it('refuses a token that was not ours', function () {
    $this->post('/reset-password', [
        'token' => 'not-a-real-token',
        'email' => 'host@example.com',
        'password' => 'a-brand-new-one',
        'password_confirmation' => 'a-brand-new-one',
    ])->assertInvalid(['email']);

    $this->post('/login', ['email' => 'host@example.com', 'password' => 'password'])
        ->assertRedirect('/dashboard');
});

// One use. Otherwise the link sitting in a mailbox is a spare key to the account
// for as long as the mailbox lives.
it('will not spend the same token twice', function () {
    $token = Password::createToken($this->host);
    $body = [
        'token' => $token,
        'email' => 'host@example.com',
        'password' => 'a-brand-new-one',
        'password_confirmation' => 'a-brand-new-one',
    ];

    $this->post('/reset-password', $body)->assertRedirect('/login');
    $this->post('/reset-password', [...$body, 'password' => 'later-still', 'password_confirmation' => 'later-still'])
        ->assertInvalid(['email']);
});

it('holds the new password to the same rules as registration', function () {
    $token = Password::createToken($this->host);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => 'host@example.com',
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertInvalid(['password']);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => 'host@example.com',
        'password' => 'a-brand-new-one',
        'password_confirmation' => 'something-else',
    ])->assertInvalid(['password']);
});

it('throttles requests for a link', function () {
    Notification::fake();

    foreach (range(1, 6) as $attempt) {
        $this->post('/forgot-password', ['email' => "guess{$attempt}@example.com"])->assertStatus(302);
    }

    $this->post('/forgot-password', ['email' => 'guess7@example.com'])->assertStatus(429);
});

// The email is the only part of this a host ever sees, and it goes out under the
// app's name to somebody who may have forgotten they have an account.
it('sends an email that says who it is from and where to go', function () {
    $mail = (new ResetPassword('a-token'))->toMail($this->host);
    $rendered = (string) $mail->render();

    expect($mail->subject)->toContain('Photobooth')
        ->and($rendered)->toContain('/reset-password/a-token')
        ->and($rendered)->toContain('Photobooth');
});

it('does not let a logged-in host wander back into the reset flow', function () {
    $this->actingAs($this->host)->get('/forgot-password')->assertRedirect('/dashboard');
});
