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

it('defaults to the classic template when none is chosen', function () {
    $this->post('/events', ['name' => 'Summer Party']);

    expect(Event::sole()->template)->toBe('classic');
});

it('saves the chosen strip template', function () {
    $this->post('/events', ['name' => 'Grid Party', 'template' => 'grid']);

    expect(Event::sole()->template)->toBe('grid');
});

it('rejects an unknown template', function () {
    $this->post('/events', ['name' => 'Party', 'template' => 'hexagon'])->assertInvalid(['template']);

    expect(Event::count())->toBe(0);
});

it('offers the template choices on the create form', function () {
    $this->get('/new')
        ->assertSee('name="template"', false)
        ->assertSee('Grid · 2×2');
});

it('saves a custom caption and colour theme', function () {
    $this->post('/events', ['name' => 'Sarah 30', 'caption' => '#Sarah30', 'theme' => 'blush']);

    $event = Event::sole();
    expect($event->caption)->toBe('#Sarah30')
        ->and($event->theme)->toBe('blush');
});

it('defaults the theme to midnight and leaves the caption empty', function () {
    $this->post('/events', ['name' => 'Plain Party']);

    $event = Event::sole();
    expect($event->theme)->toBe('midnight')
        ->and($event->caption)->toBeNull();
});

it('rejects an unknown colour theme', function () {
    $this->post('/events', ['name' => 'Party', 'theme' => 'neon'])->assertInvalid(['theme']);

    expect(Event::count())->toBe(0);
});

it('offers the colour themes on the create form', function () {
    $this->get('/new')
        ->assertSee('name="theme"', false)
        ->assertSee('Champagne')
        ->assertSee('name="caption"', false);
});

it('rejects a blank name', function () {
    $this->post('/events', ['name' => ''])->assertInvalid(['name']);

    expect(Event::count())->toBe(0);
});

it('links to event creation from the home page', function () {
    $this->get('/')->assertSee('/new');
});
