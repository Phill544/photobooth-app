<?php

use App\Models\User;

it('promotes a user to admin by email', function () {
    $user = User::factory()->create(['email' => 'host@example.com']);
    expect($user->refresh()->is_admin)->toBeFalse();

    $this->artisan('photobooth:make-admin host@example.com')->assertSuccessful();

    expect($user->refresh()->is_admin)->toBeTrue();
});

it('fails when no user has that email', function () {
    $this->artisan('photobooth:make-admin ghost@example.com')->assertFailed();
});
