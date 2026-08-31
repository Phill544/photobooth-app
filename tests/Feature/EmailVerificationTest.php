<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

function verificationUrl(User $user): string
{
    return URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->getKey(),
        'hash' => sha1($user->getEmailForVerification()),
    ]);
}

beforeEach(function () {
    $this->unverified = User::factory()->create(['email_verified_at' => null]);
});

it('emails a new host a link to verify the address they typed', function () {
    Notification::fake();

    $this->post('/register', [
        'name' => 'New Host',
        'email' => 'new@example.com',
        'password' => 'a-good-password',
        'password_confirmation' => 'a-good-password',
    ])->assertRedirect('/dashboard');

    Notification::assertSentTo(User::whereEmail('new@example.com')->sole(), VerifyEmail::class);
});

// Verification gates the one thing worth gating and nothing else: a host is
// never locked out of an event that already exists, least of all mid-night.
it('lets an unverified host log in and manage what they already have', function () {
    $event = Event::create(['name' => 'Summer Party', 'code' => 'PARTY2', 'owner_id' => $this->unverified->id]);

    $this->actingAs($this->unverified)->get('/dashboard')->assertOk();
    $this->actingAs($this->unverified)->get('/events/PARTY2')->assertOk();
    $this->actingAs($this->unverified)->post('/events/PARTY2/toggle-closed')->assertRedirect('/events/PARTY2');

    expect($event->refresh()->isClosed())->toBeTrue();
});

it('stops an unverified host opening a new booth', function () {
    $this->actingAs($this->unverified)->get('/new')->assertRedirect('/email/verify');

    $this->actingAs($this->unverified)->post('/events', ['name' => 'Summer Party'])
        ->assertRedirect('/email/verify');

    expect(Event::count())->toBe(0);
});

it('says why, and offers to send the link again', function () {
    $this->actingAs($this->unverified)->get('/email/verify')
        ->assertOk()
        ->assertSee($this->unverified->email)
        ->assertSee('Send it again');
});

it('sends the link again when asked', function () {
    Notification::fake();

    $this->actingAs($this->unverified)->post('/email/resend')->assertRedirect('/email/verify');

    Notification::assertSentTo($this->unverified, VerifyEmail::class);
});

it('throttles asking for it again', function () {
    Notification::fake();

    foreach (range(1, 6) as $attempt) {
        $this->actingAs($this->unverified)->post('/email/resend')->assertStatus(302);
    }

    $this->actingAs($this->unverified)->post('/email/resend')->assertStatus(429);
});

it('verifies the address and lands the host where they were headed', function () {
    $this->actingAs($this->unverified)->get('/new'); // sets the intended URL

    $this->actingAs($this->unverified)->get(verificationUrl($this->unverified))
        ->assertRedirect('/new');

    expect($this->unverified->refresh()->hasVerifiedEmail())->toBeTrue();

    $this->actingAs($this->unverified->refresh())->get('/new')->assertOk();
});

it('refuses a link nobody signed', function () {
    $this->actingAs($this->unverified)
        ->get("/email/verify/{$this->unverified->id}/".sha1($this->unverified->email))
        ->assertForbidden();

    expect($this->unverified->refresh()->hasVerifiedEmail())->toBeFalse();
});

// A signed link is only proof of the address it was sent to. Following somebody
// else's must not verify yours.
it('refuses a link signed for a different account', function () {
    $other = User::factory()->create(['email_verified_at' => null]);

    $this->actingAs($this->unverified)->get(verificationUrl($other))->assertForbidden();

    expect($this->unverified->refresh()->hasVerifiedEmail())->toBeFalse()
        ->and($other->refresh()->hasVerifiedEmail())->toBeFalse();
});

// The link sits in a mailbox; a second tap on it must not be an error page.
it('shrugs at a link that has already been used', function () {
    $url = verificationUrl($this->unverified);

    $this->actingAs($this->unverified)->get($url);
    $this->actingAs($this->unverified->refresh())->get($url)->assertRedirect('/dashboard');
});

it('nags on the dashboard until it is done', function () {
    $this->actingAs($this->unverified)->get('/dashboard')
        ->assertOk()
        ->assertSee('Confirm your email');

    $this->actingAs(User::factory()->create())->get('/dashboard')
        ->assertOk()
        ->assertDontSee('Confirm your email');
});

// The gate is only fair while the app can actually send the link. With no
// mailer, requiring one would be a locked door with no key cut for it — and
// DEPLOY.md is explicit that a failing deploy command may not abort the release.
it('does not gate anything when there is no mailer to verify with', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['mail.default' => 'log']);

    $this->actingAs($this->unverified)->get('/new')->assertOk();
    $this->actingAs($this->unverified)->get('/dashboard')->assertDontSee('Confirm your email');
});

it('sends an email that says who it is from', function () {
    $mail = (new VerifyEmail)->toMail($this->unverified);

    expect($mail->subject)->toContain('Photobooth')
        ->and((string) $mail->render())->toContain('Photobooth');
});

// Everyone who had an account before this shipped was told nothing about
// verifying, and a deploy that gates them is a deploy that breaks their night.
it('marks the hosts who already existed as verified', function () {
    $existing = User::factory()->create(['email_verified_at' => null]);

    (require database_path('migrations/2026_08_31_000003_verify_the_hosts_who_already_existed.php'))->up();

    expect($existing->refresh()->hasVerifiedEmail())->toBeTrue();
});
