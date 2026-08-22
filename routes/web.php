<?php

use App\Http\Controllers\EventController;
use App\Http\Controllers\PhotoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('home');
});

Route::get('/new', [EventController::class, 'create']);
Route::post('/events', [EventController::class, 'store']);
Route::get('/events/{event:code}', [EventController::class, 'show']);
Route::post('/events/{event:code}/toggle-closed', [EventController::class, 'toggleClosed']);

Route::get('/e/{event:code}', [EventController::class, 'capture']);
Route::get('/e/{event:code}/gallery', [EventController::class, 'gallery']);
Route::post('/e/{event:code}/photos', [PhotoController::class, 'store'])->middleware('throttle:uploads');
Route::get('/e/{event:code}/photos/{photo}', [PhotoController::class, 'show'])->scopeBindings();
Route::delete('/e/{event:code}/groups/{group}', [PhotoController::class, 'destroyGroup']);
