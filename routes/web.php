<?php

use App\Http\Controllers\EventController;
use App\Http\Controllers\PhotoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('home');
});

Route::get('/e/{event:code}', [EventController::class, 'capture']);
Route::get('/e/{event:code}/gallery', [EventController::class, 'gallery']);
Route::post('/e/{event:code}/photos', [PhotoController::class, 'store']);
Route::get('/e/{event:code}/photos/{photo}', [PhotoController::class, 'show'])->scopeBindings();
