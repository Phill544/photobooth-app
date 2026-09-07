<?php

use App\Models\User;
use App\Notifications\QueuedResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Nightwatch\Core;

// Nightwatch is a production dependency and is live (confirmed 2026-09-06,
// storage region Sydney, so this is a third party rather than a border
// crossing). Two things it takes by default that it has no need for, and both
// are host personal information.
//
// The sampling rates all default to 1.0 — this is not a sample, it is every
// request — so anything Nightwatch collects, it collects for everything.

it('sends Nightwatch a user id and nothing else about the host', function () {
    $user = User::factory()->create(['name' => 'Alex Host', 'email' => 'alex@example.com']);

    $resolver = app(Core::class)->userDetailsResolver;

    expect($resolver)->not->toBeNull('no resolver is registered, so Nightwatch sends name and email');

    $details = $resolver($user);

    expect($details)->not->toHaveKey('name')
        ->and($details)->not->toHaveKey('username')
        ->and(json_encode($details))->not->toContain('Alex Host')
        ->and(json_encode($details))->not->toContain('alex@example.com');
});

// Nightwatch records full URLs, query strings included, and there is no config
// to scrub them. The reset link used to carry `?email=`, which put both halves
// of the credential — the token in the path and the address it is checked
// against — into the vendor's store on every reset click.
//
// The form has always been able to cope: the field is editable and takes focus
// when the address is absent, because a host whose mail forwards may not arrive
// as themselves anyway.
it('keeps the address out of the reset link', function () {
    $user = User::factory()->create(['email' => 'alex@example.com']);

    $link = null;
    Notification::fake();
    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user,
        QueuedResetPassword::class,
        function ($notification) use ($user, &$link) {
            $link = $notification->toMail($user)->actionUrl;

            return true;
        });

    expect($link)->not->toContain('email=')
        ->and($link)->not->toContain('alex@example.com')
        ->and($link)->toContain('/reset-password/');
});

// The controller still reads `?email=` from the query even though nothing in the
// repo produces it any more. That branch is not dead: a link sent before this
// change is valid for an hour, and a host who clicks one must not meet a broken
// form. Deleting the read would have been the quiet way to break them.
it('still honours a link sent before the address was dropped', function () {
    $user = User::factory()->create(['email' => 'alex@example.com']);
    $token = Password::createToken($user);

    $this->get("/reset-password/{$token}?email=".urlencode($user->email))
        ->assertOk()
        ->assertSee('value="alex@example.com"', false);
});

it('still lets a host reset with the address they type in', function () {
    $user = User::factory()->create(['email' => 'alex@example.com']);
    $token = Password::createToken($user);

    $this->get("/reset-password/{$token}")->assertOk()->assertSee('name="email"', false);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => 'alex@example.com',
        'password' => 'a-new-password',
        'password_confirmation' => 'a-new-password',
    ])->assertRedirect('/login');

    expect(Hash::check('a-new-password', $user->refresh()->password))->toBeTrue();
});
