<?php

use App\Models\Event;

// The join form is the only way into a booth that a guest types, and it lived
// entirely in a submit handler: no action, no method, and the navigation was a
// `location.href` inside the JS. Without JS the submit re-GET the current page,
// which ignores `?code=`, so the guest saw an empty field and nothing moved —
// and on the unknown-code 404 a retry re-served the same 404 forever.

it('sends a plain form submit into the booth', function () {
    Event::create(['name' => 'Garden Party', 'code' => 'GARDEN']);

    $this->get('/join?code=GARDEN')->assertRedirect('/e/GARDEN');
});

it('reads a code the way it was read off a sign', function () {
    Event::create(['name' => 'Garden Party', 'code' => 'GARDEN']);

    $this->get('/join?code=garden')->assertRedirect('/e/GARDEN');
    // What a browser actually sends for a field somebody pasted with spaces.
    $this->get('/join?code=%20garden%20')->assertRedirect('/e/GARDEN');
});

// The redirect is where the code gets looked up, so an unknown one lands on the
// same friendly 404 as a typed URL rather than being judged here.
it('lets an unknown code reach the page that names it', function () {
    $this->get('/join?code=ZZZZZZ')
        ->assertRedirect('/e/ZZZZZZ');

    $this->get('/e/ZZZZZZ')->assertNotFound()->assertSee('ZZZZZZ');
});

// ?code[]=x is one curl away, and this is a public unauthenticated GET linked
// from the home page — a 500 here is noise in the error tracker forever.
it('shrugs off a code that is not a string at all', function () {
    $this->get('/join?code[]=GARDEN')->assertRedirect('/');
});

it('sends an empty submit back to the front door', function () {
    $this->get('/join')->assertRedirect('/');
    $this->get('/join?code=')->assertRedirect('/');
});

it('posts the form somewhere real on every page that carries it', function () {
    $this->get('/')->assertSee('action="/join"', false);
    $this->get('/e/ZZZZZZ')->assertNotFound()->assertSee('action="/join"', false);
});

it('is a GET, so a guest can retry it without a resubmit warning', function () {
    $this->get('/')->assertSee('method="get"', false);
});
