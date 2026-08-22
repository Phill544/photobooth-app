<?php

use App\Models\User;

it('shows the register form', function () {
    $this->get('/register')->assertOk()->assertSee('name="email"', false);
});

it('registers a new owner and logs them in', function () {
    $this->post('/register', [
        'name' => 'Host',
        'email' => 'host@example.com',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])->assertRedirect('/dashboard');

    $this->assertAuthenticated();
    expect(User::where('email', 'host@example.com')->exists())->toBeTrue();
});

it('requires a unique email to register', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->post('/register', [
        'name' => 'X', 'email' => 'taken@example.com',
        'password' => 'secret-password', 'password_confirmation' => 'secret-password',
    ])->assertInvalid(['email']);
});

it('requires the password to be confirmed', function () {
    $this->post('/register', [
        'name' => 'X', 'email' => 'x@example.com',
        'password' => 'secret-password', 'password_confirmation' => 'different',
    ])->assertInvalid(['password']);

    $this->assertGuest();
});

it('does not let registration grant admin', function () {
    $this->post('/register', [
        'name' => 'Sneaky', 'email' => 'sneaky@example.com',
        'password' => 'secret-password', 'password_confirmation' => 'secret-password',
        'is_admin' => '1',
    ])->assertRedirect('/dashboard');

    expect(User::where('email', 'sneaky@example.com')->sole()->is_admin)->toBeFalse();
});

it('shows the login form', function () {
    $this->get('/login')->assertOk()->assertSee('name="email"', false);
});

it('logs in with correct credentials', function () {
    User::factory()->create(['email' => 'host@example.com', 'password' => 'secret-password']);

    $this->post('/login', ['email' => 'host@example.com', 'password' => 'secret-password'])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticated();
});

it('rejects a wrong password', function () {
    User::factory()->create(['email' => 'host@example.com', 'password' => 'secret-password']);

    $this->post('/login', ['email' => 'host@example.com', 'password' => 'nope'])
        ->assertInvalid(['email']);

    $this->assertGuest();
});

it('logs out', function () {
    $this->actingAs(User::factory()->create())->post('/logout')->assertRedirect('/');

    $this->assertGuest();
});

it('sends an already-authenticated visitor away from the login page', function () {
    $this->actingAs(User::factory()->create())->get('/login')->assertRedirect('/dashboard');
});
