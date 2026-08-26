<?php

use App\Http\Controllers\EventController;
use App\Http\Controllers\PhotoController;
use Illuminate\Support\Facades\Route;

// Immutable files behind an event code, asked for dozens at a time as an album
// scrolls. They resolve route bindings like everything else, but skip the web
// group: there is no session to start, no CSRF token to check and no cookie
// worth setting on a photo.
Route::get('/e/{event:code}/logo', [EventController::class, 'logo']);
Route::get('/e/{event:code}/photos/{photo}', [PhotoController::class, 'show'])->scopeBindings();
Route::get('/e/{event:code}/photos/{photo}/thumb', [PhotoController::class, 'thumb'])->scopeBindings();
