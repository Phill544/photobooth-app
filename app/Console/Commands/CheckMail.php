<?php

namespace App\Console\Commands;

use App\Support\Deliverability;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

// Run this as a deploy command, beside photobooth:check-storage: it fails the
// release rather than letting one go live whose password-reset page promises an
// email nothing will send.
class CheckMail extends Command
{
    protected $signature = 'photobooth:check-mail {--to= : Also send a real test message to this address}';

    protected $description = 'Fail if mail would go nowhere, so a reset link is never promised in vain';

    public function handle(): int
    {
        $mailer = config('mail.default');

        if (Deliverability::mailerIsFake()) {
            $this->error("The default mailer is [{$mailer}], and this is the ".app()->environment().' environment.');
            $this->error('Nothing it is handed will ever arrive. Set MAIL_MAILER and its credentials — DEPLOY.md has the SES steps.');

            return self::FAILURE;
        }

        // A from address is not decoration: SES will not send from a domain it
        // has not verified, and the framework's placeholder is a domain nobody
        // owns — which bounces, or lands in spam, and looks from the host's side
        // exactly like no mailer at all.
        $from = config('mail.from.address');

        if (! $from || str_ends_with($from, '@example.com')) {
            $this->error('MAIL_FROM_ADDRESS is '.($from ? "still the placeholder [{$from}]" : 'not set').'.');
            $this->error('Set it to an address on a domain the transport is allowed to send from.');

            return self::FAILURE;
        }

        $this->info("Mail OK — mailer [{$mailer}], from [{$from}].");

        return $this->option('to') ? $this->sendProbe() : self::SUCCESS;
    }

    // Configuration that looks right and credentials that work are two different
    // questions, and only one of them can be answered without sending something.
    private function sendProbe(): int
    {
        $to = $this->option('to');

        Mail::raw(
            'Quikbooth mail check. If you are reading this, the transport works and password reset will reach hosts.',
            fn ($message) => $message->to($to)->subject('Quikbooth mail check'),
        );

        $this->info("Sent a test message to {$to}. It has to actually arrive — a transport can accept and drop.");

        return self::SUCCESS;
    }
}
