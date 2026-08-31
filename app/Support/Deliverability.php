<?php

namespace App\Support;

class Deliverability
{
    // True when the configured mailer does not actually send anything. This is
    // the same shape as the disk trap in Durability: `log` is the framework's
    // default, it writes a reset link into a file nobody reads, and it fails
    // exactly like success — the page says "check your email" and the host waits
    // for a mail that was never posted. Laravel Cloud injects a database, a disk
    // and a queue, but it has no mail service to inject, so nothing sets this
    // for you.
    //
    // Local and testing are exempt: `log` and `array` are the right mailers
    // there, and a dev with no SES credentials must still be able to work.
    public static function mailerIsFake(): bool
    {
        return in_array(config('mail.default'), ['log', 'array'], true)
            && ! app()->environment('local', 'testing');
    }
}
