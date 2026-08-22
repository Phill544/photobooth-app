<?php

use App\Models\Event;

it('shows the create form', function () {
    $this->get('/new')
        ->assertOk()
        ->assertSee('name="name"', false);
});

it('creates an event and redirects to its owner page', function () {
    $response = $this->post('/events', ['name' => 'Summer Party']);

    $event = Event::sole();
    expect($event->name)->toBe('Summer Party');
    $response->assertRedirect("/events/{$event->code}");
});

it('rejects a blank name', function () {
    $this->post('/events', ['name' => ''])->assertInvalid(['name']);

    expect(Event::count())->toBe(0);
});

it('links to event creation from the home page', function () {
    $this->get('/')->assertSee('/new');
});
