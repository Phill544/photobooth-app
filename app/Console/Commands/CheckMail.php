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
            $this->error('Nothing it is handed will ever arrive. Set MAIL_MAILER and its credentials — MAIL.md has the Resend steps.');

            return self::FAILURE;
        }

        // A from address is not decoration: Resend will not send from a domain it
        // has not verified, and the framework's placeholder is a domain nobody
        // owns — which bounces, or lands in spam, and looks from the host's side
        // exactly like no mailer at all.
        $from = config('mail.from.address');

        if (! $from || str_ends_with($from, '@example.com')) {
            $this->error('MAIL_FROM_ADDRESS is '.($from ? "still the placeholder [{$from}]" : 'not set').'.');
            $this->error('Set it to an address on a domain the transport is allowed to send from.');

            return self::FAILURE;
        }

        // The one credential this app sends with, and a blank one gets past the
        // build below: Resend::client('') is a legal call, so the transport
        // constructs happily and the 401 waits for the first real send.
        if ($mailer === 'resend' && ! config('services.resend.key')) {
            $this->error('The mailer is [resend] but RESEND_API_KEY is empty.');
            $this->error('On Laravel Cloud the variable has to exist before the deploy that runs config:cache, or the cached config keeps a null.');

            return self::FAILURE;
        }

        // Naming a transport is not the same as having one. `MAIL_MAILER=resend`
        // with resend/resend-php missing throws `Class "Resend" not found`, and a
        // null credential throws on the way in — both `Error`s rather than
        // `Exception`s, so `catch (Exception)` would sail straight past them, and
        // both would otherwise surface in a queue worker where nobody is
        // watching. Build it here instead, where a non-zero exit still means
        // something.
        try {
            Mail::mailer($mailer)->getSymfonyTransport();
        } catch (\Throwable $e) {
            $this->error("The mailer is [{$mailer}] but its transport will not build: ".$e->getMessage());
            $this->error('Check that the release actually carries the transport package. MAIL.md has the rest.');

            return self::FAILURE;
        }

        $this->info("Mail OK — mailer [{$mailer}] builds, from [{$from}].");

        if (! $this->option('to')) {
            // Say out loud what a green run does not cover, because the whole
            // failure mode this command exists for is somebody trusting it.
            $this->line('That is configuration, not delivery — a key can still be rejected and a recipient suppressed.');
            $this->line('Add --to=<a-real-mailbox-you-can-open> to send one. Never an invented address: one hard bounce is 4%.');
        }

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
