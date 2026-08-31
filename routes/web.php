<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\PhotoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('home');
});

// --- Auth ---
Route::middleware('guest')->group(function () {
    Route::get('/register', [AuthController::class, 'showRegister']);
    // Ten auth attempts a minute per address. The unnamed throttle keys on the
    // address alone, so register and login share one budget — which is the
    // point: both are guesses at an account from the same place.
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth');

// --- Owner: create + manage events (login required) ---
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [EventController::class, 'dashboard']);
    Route::get('/new', [EventController::class, 'create']);
    Route::post('/events', [EventController::class, 'store']);
    Route::get('/events/{event:code}', [EventController::class, 'show']);
    Route::patch('/events/{event:code}', [EventController::class, 'update']);
    Route::delete('/events/{event:code}', [EventController::class, 'destroy']);
    Route::post('/events/{event:code}/toggle-closed', [EventController::class, 'toggleClosed']);
    Route::post('/events/{event:code}/privacy', [EventController::class, 'privacy']);
    Route::post('/events/{event:code}/retention', [EventController::class, 'retention']);
    Route::delete('/e/{event:code}/groups/{group}', [PhotoController::class, 'destroyGroup']);
});

// --- Guests: join by code, no login (the event code is the credential) ---
Route::get('/e/{event:code}', [EventController::class, 'capture']);
Route::get('/e/{event:code}/gallery', [EventController::class, 'gallery']);
Route::post('/e/{event:code}/gallery/unlock', [EventController::class, 'unlock'])->middleware('throttle:album-pin');
Route::post('/e/{event:code}/photos', [PhotoController::class, 'store'])->middleware('throttle:uploads');

// The image routes themselves live in routes/images.php — they have no use for a
// session, so they are registered outside this group (see bootstrap/app.php).
