<?php

use App\Models\Event;
use Illuminate\Support\Facades\Storage;

// A mistyped code is the most likely 404 this app will ever serve: the code is
// read off a sign or a table card and typed by hand. Answer it with the code
// that was tried and another go at the form, not a bare error page.

it('names the code that was tried and offers the form again', function () {
    $this->get('/e/ZZZZZZ')
        ->assertNotFound()
        ->assertSee('ZZZZZZ')
        ->assertSee('name="code"', false);
});

it('shows the tried code the way codes are written', function () {
    $this->get('/e/zzzzzz')->assertNotFound()->assertSee('ZZZZZZ');
});

it('answers an unknown album the same way', function () {
    $this->get('/e/ZZZZZZ/gallery')->assertNotFound()->assertSee('ZZZZZZ');
});

it('offers the form on any other missing page too', function () {
    $this->get('/nowhere')->assertNotFound()->assertSee('name="code"', false);
});

// The event is real here — only the photo is gone, so nothing is wrong with
// the code and the page must not say there is.
it('does not blame the code when the event exists', function () {
    Storage::fake();
    Event::create(['name' => 'Summer Party', 'code' => 'PARTY2']);

    $this->get('/e/PARTY2/photos/999')->assertNotFound()->assertDontSee('PARTY2');
});

// The booth's uploader asks for JSON and parses the reply; an HTML page here
// would be a parse error instead of a status it can act on.
it('still answers an upload to an unknown code with json', function () {
    $this->postJson('/e/ZZZZZZ/photos', [])
        ->assertNotFound()
        ->assertJsonStructure(['message']);
});
