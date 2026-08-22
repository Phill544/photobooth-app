<?php

use App\Models\Event;

beforeEach(function () {
    $this->event = Event::create(['name' => 'Summer Party', 'code' => 'PARTY2', 'theme' => 'midnight']);
});

it('updates the booth name, layout, colour, and caption', function () {
    $this->patch('/events/PARTY2', [
        'name' => 'Sarah 30',
        'template' => 'grid',
        'theme' => 'blush',
        'caption' => '#Sarah30',
    ])->assertRedirect('/events/PARTY2');

    $event = $this->event->refresh();
    expect($event->name)->toBe('Sarah 30')
        ->and($event->template)->toBe('grid')
        ->and($event->theme)->toBe('blush')
        ->and($event->caption)->toBe('#Sarah30');
});

it('clears the caption back to the event name when emptied', function () {
    $this->event->update(['caption' => 'Old caption']);

    $this->patch('/events/PARTY2', ['name' => 'Summer Party', 'caption' => '']);

    expect($this->event->refresh()->caption)->toBeNull();
});

it('rejects an unknown colour theme on update', function () {
    $this->patch('/events/PARTY2', ['name' => 'Summer Party', 'theme' => 'neon'])
        ->assertInvalid(['theme']);

    expect($this->event->refresh()->theme)->toBe('midnight');
});

it('404s when editing an unknown event', function () {
    $this->patch('/events/XXXXXX', ['name' => 'Nope'])->assertNotFound();
});

it('shows the edit form prefilled on the owner page', function () {
    $this->event->update(['caption' => 'Cheers!']);

    $this->get('/events/PARTY2')
        ->assertOk()
        ->assertSee('data-strip-form', false)
        ->assertSee('value="Cheers!"', false);
});
