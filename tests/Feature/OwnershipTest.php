<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

it('requires login to reach the create form and dashboard', function () {
    $this->get('/new')->assertRedirect('/login');
    $this->get('/dashboard')->assertRedirect('/login');
    $this->post('/events', ['name' => 'X'])->assertRedirect('/login');
});

it('sets the logged-in user as the owner when creating', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)->post('/events', ['name' => 'My Party']);

    expect(Event::sole()->owner_id)->toBe($owner->id);
});

it('stops one owner from viewing or managing another owner\'s event', function () {
    $mine = Event::create(['name' => 'Mine', 'code' => 'MINE22', 'owner_id' => User::factory()->create()->id]);
    $intruder = User::factory()->create();

    $this->actingAs($intruder)->get('/events/MINE22')->assertForbidden();
    $this->actingAs($intruder)->patch('/events/MINE22', ['name' => 'Hijacked'])->assertForbidden();
    $this->actingAs($intruder)->post('/events/MINE22/toggle-closed')->assertForbidden();

    expect($mine->refresh()->name)->toBe('Mine');
});

it('lets an admin manage any event', function () {
    Event::create(['name' => 'Someone Else', 'code' => 'OTHER2', 'owner_id' => User::factory()->create()->id]);
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->get('/events/OTHER2')->assertOk();
    $this->actingAs($admin)->patch('/events/OTHER2', ['name' => 'Renamed'])->assertRedirect('/events/OTHER2');
});

it('shows an owner only their own events on the dashboard', function () {
    $me = User::factory()->create();
    Event::create(['name' => 'My Gig', 'code' => 'MINE22', 'owner_id' => $me->id]);
    Event::create(['name' => 'Not Mine', 'code' => 'THEM22', 'owner_id' => User::factory()->create()->id]);

    $this->actingAs($me)->get('/dashboard')->assertOk()->assertSee('My Gig')->assertDontSee('Not Mine');
});

it('shows an admin every event on the dashboard', function () {
    Event::create(['name' => 'Alpha', 'code' => 'AAAA22', 'owner_id' => User::factory()->create()->id]);
    Event::create(['name' => 'Beta', 'code' => 'BBBB22', 'owner_id' => User::factory()->create()->id]);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get('/dashboard')->assertOk()->assertSee('Alpha')->assertSee('Beta');
});

it('only the owner may delete a session', function () {
    Storage::fake();
    $owner = User::factory()->create();
    $event = Event::create(['name' => 'Mine', 'code' => 'MINE22', 'owner_id' => $owner->id]);
    $group = fake()->uuid();
    uploadPhoto('MINE22', ['group' => $group]);

    $this->actingAs(User::factory()->create())->delete("/e/MINE22/groups/$group")->assertForbidden();
    expect($event->photos()->count())->toBe(1);

    $this->actingAs($owner)->delete("/e/MINE22/groups/$group")->assertRedirect('/e/MINE22/gallery');
    expect($event->photos()->count())->toBe(0);
});

it('keeps the guest flow open without any login', function () {
    Storage::fake();
    Event::create(['name' => 'Open', 'code' => 'OPEN22', 'owner_id' => User::factory()->create()->id]);

    $this->get('/e/OPEN22')->assertOk();               // capture page
    $this->get('/e/OPEN22/gallery')->assertOk();       // album
    uploadPhoto('OPEN22')->assertCreated();            // upload
});
