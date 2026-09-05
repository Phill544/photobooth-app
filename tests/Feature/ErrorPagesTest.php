<?php

use App\Models\Event;
use App\Models\User;

// 404 was the only error page this app had, so every other way a guest can be
// refused — someone else's event, a page that sat open past its session, a
// hand that tried the PIN too many times — served Laravel's bare page: no
// wordmark, no code entry, no way onward. Each of these is a guest holding a
// phone at a party, not a developer reading a stack trace.

it('tells you it is not your event, and where you can go instead', function () {
    Event::create(['name' => 'Someone Else', 'code' => 'OTHER2', 'owner_id' => User::factory()->create()->id]);

    $this->actingAs(User::factory()->create())
        ->get('/events/OTHER2')
        ->assertForbidden()
        ->assertSee('isn’t your event', false)
        ->assertSee('name="code"', false);
});

it('gives every refusal page the two generic doors', function () {
    foreach ([403, 419, 429] as $status) {
        $page = view("errors.{$status}")->render();

        // The 404's two doors: a code gets you into a booth, and a host signs in.
        expect($page)
            ->toContain('name="code"')
            ->toContain('action="/join"')
            ->toContain('/dashboard');
    }
});

// The event code is the credential, so these pages must never be indexed or
// they become a list of the ways in.
it('keeps the refusal pages out of search results', function () {
    foreach ([403, 419, 429] as $status) {
        expect(view("errors.{$status}")->render())
            ->toContain('<meta name="robots" content="noindex, nofollow">');
    }
});

it('names what actually went wrong on each one', function () {
    expect(view('errors.419')->render())->toContain('sat open');
    expect(view('errors.429')->render())->toContain('wait');
});
