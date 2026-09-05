<?php

use App\Http\Controllers\ArchiveController;
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
    // Ten auth attempts a minute. The unnamed throttle keys on the IP, not the
    // address, so register and login share one budget — which suits these two,
    // since both are guesses at an account from the same place. It does mean a
    // venue behind one NAT address shares it, which is why the guest-facing
    // ones are keyed on the event code instead — `uploads` as a named limiter
    // below, and album PINs inside EventController::unlock().
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    // Forgotten password. The GET pages are inside the guest group because a
    // host who is already in has no business here; `password.reset` keeps its
    // framework name because the notification builds the link from it.
    //
    // The third argument is a bucket name, and it is the point: an unnamed
    // throttle keys on the IP alone — not the route, not the limit — so these
    // would otherwise share one counter with register and login, and the
    // smallest cap on it would win. Six failed logins would then 429 the one
    // form that lets a host who has forgotten their password back in.
    Route::get('/forgot-password', [AuthController::class, 'showForgotPassword']);
    Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])->middleware('throttle:6,1,reset');
    Route::get('/reset-password/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:6,1,reset');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth');

// Confirming a host's address. Signed rather than guessable, and `auth` on all
// three because the link is only proof of the address of whoever is signed in.
Route::middleware('auth')->group(function () {
    Route::get('/email/verify', [AuthController::class, 'showVerifyNotice']);
    Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware('signed')->name('verification.verify');
    Route::post('/email/resend', [AuthController::class, 'resendVerification'])->middleware('throttle:6,1');
});

// --- Owner: create + manage events (login required) ---
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [EventController::class, 'dashboard']);
    // The one thing an unverified address may not do. Everything else stays
    // reachable, so a typo in an address never costs a host the event they are
    // already running (App\Http\Middleware\EnsureEmailIsVerified).
    Route::middleware('verified')->group(function () {
        Route::get('/new', [EventController::class, 'create']);
        Route::post('/events', [EventController::class, 'store']);
    });
    Route::get('/events/{event:code}', [EventController::class, 'show']);
    Route::patch('/events/{event:code}', [EventController::class, 'update']);
    Route::delete('/events/{event:code}', [EventController::class, 'destroy']);
    Route::post('/events/{event:code}/toggle-closed', [EventController::class, 'toggleClosed']);
    Route::post('/events/{event:code}/privacy', [EventController::class, 'privacy']);
    Route::post('/events/{event:code}/retention', [EventController::class, 'retention']);
    Route::post('/events/{event:code}/archive', [ArchiveController::class, 'store']);
    Route::delete('/e/{event:code}/groups/{group}', [PhotoController::class, 'destroyGroup']);
});

// --- Guests: join by code, no login (the event code is the credential) ---
Route::get('/e/{event:code}', [EventController::class, 'capture']);
Route::get('/e/{event:code}/gallery', [EventController::class, 'gallery']);
Route::post('/e/{event:code}/gallery/unlock', [EventController::class, 'unlock']); // rationed inside, where a wrong guess can be told from a guest
Route::post('/e/{event:code}/photos', [PhotoController::class, 'store'])->middleware('throttle:uploads');

// Taking the night home. No `auth`: the signature IS the credential, because
// this link is emailed and gets opened on whatever device reads the mail rather
// than the one the host signed in on. It carries the file's own expiry.
Route::get('/archives/{archive}/download', [ArchiveController::class, 'download'])
    ->middleware('signed')->name('archive.download');

// The image routes themselves live in routes/images.php — they have no use for a
// session, so they are registered outside this group (see bootstrap/app.php).
