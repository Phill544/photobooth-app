<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

// The framework's verification notification, queued, for the reason the sibling
// class next door spells out — and for one this app learned the hard way.
//
// Registration fires this from `event(new Registered(...))`, which runs after
// the account row is written and before Auth::login. Sending inline meant a
// transport that refused the address threw between those two: the host got a
// 500, ended up with an account they were never signed into, and could not
// register again because the address was taken.
class QueuedVerifyEmail extends VerifyEmail implements ShouldQueue
{
    use Queueable;
}
