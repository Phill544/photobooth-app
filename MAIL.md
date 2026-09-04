# Mail

Three emails, one transport, and the handful of facts about it that are easy to learn the hard way.

## What the app sends

| Mail | Sent when | Goes to |
|---|---|---|
| `QueuedResetPassword` | a host asks for a password reset | an address that already belongs to an account |
| `QueuedVerifyEmail` | a host registers, or taps "send it again" | an address **nobody has confirmed yet** |
| `ArchiveReady` | a download-all archive finishes building | the host who asked for it |

Nothing else in the app sends mail, so nothing else is affected by anything in this file.

**All three are queued** (`ShouldQueue`), which is not tidiness — it is the production incident of
2026-09-01, when a sandboxed SES refused an unverified recipient and the `TransportException`
escaped `event(new Registered(...))`, after the account row was written and before `Auth::login`
ran. ARCHITECTURE.md carries the full account. Two consequences worth holding onto: a transport
that refuses a message can no longer take down the request that triggered it, and **a delivery
failure now surfaces as a failed job rather than as an error anybody sees**.

`QueuedResetPassword` is additionally `ShouldBeEncrypted`, because it carries the raw reset token
while `password_reset_tokens` deliberately keeps only its hash.

## The transport

**Resend**, since 2026-09-04. Four environment variables and one package:

```
MAIL_MAILER=resend
MAIL_FROM_ADDRESS=hello@quikbooth.com
MAIL_FROM_NAME=Quikbooth
RESEND_API_KEY=re_...
```

Laravel 13 ships `Illuminate\Mail\Transport\ResendTransport` natively, and `config/mail.php` and
`config/services.php` already carried the blocks it needs, so the only dependency is
`resend/resend-php`. Pin it `^1.13`, not the `^1.0` Resend's own Laravel guide suggests — `v1.0.0`
requires `guzzlehttp/guzzle ^7.5` and this project is locked to Guzzle 8.

The sending domain is the apex `quikbooth.com`, verified in `us-east-1`, with open and click
tracking **off**. Tracking has to stay off: click tracking rewrites every link in the HTML body,
and two of the three mails carry a single-use signed URL that an extra hop or a link scanner can
spend before the host ever taps it.

**Locally, leave `MAIL_MAILER=log`.** The link lands in `storage/logs/laravel.log`, one grep away,
and `local` and `testing` are exempt from the guards below so a dev with no Resend key can still
work on these pages.

## The guards, and what they do not prove

The mailer has the shape of the trap that cost this app its first photos: a default that quietly
does nothing while the UI says it worked. `App\Support\Deliverability::mailerIsFake()` is the
answer, wired into two places — `photobooth:check-mail` as a deploy command, and the password-reset
pages at request time, which say so plainly instead of promising an email that cannot be sent. The
verification gate lifts entirely when the mailer is fake, because requiring a link nothing can send
is a locked door with no key cut for it.

`mailerIsFake()` tests the mailer's *name* — `in_array(config('mail.default'), ['log', 'array'])`
is the whole of it — so the request-time guards know "no mailer" and "a mailer" and nothing in
between. The deploy gate goes two steps further, because naming a transport is not the same as
having one:

- **It builds the transport.** `MAIL_MAILER=resend` with `resend/resend-php` missing throws
  `Class "Resend" not found`, and a null key throws a `TypeError` on the way in. Both are PHP
  `Error`s rather than `Exception`s, which is why the command catches `Throwable` —
  `catch (\Exception)` would sail straight past both.
- **It requires a non-blank `RESEND_API_KEY`.** That one does not come out in the build:
  `Resend::client('')` is a perfectly legal call, so the transport constructs and the 401 waits for
  the first real send.

What nothing short of an actual send can see:

- A key that is well-formed but wrong, revoked, or suspended (403).
- The sending domain not verified (403).
- The recipient on Resend's suppression list — accepted, then silently dropped.
- The daily quota exhausted (429).

So a green run means the mailer is real, buildable and credentialled. **It does not mean anything
was delivered**, and the command says as much on its way out. Only `--to=<a real mailbox>` sends,
and only a message that arrives proves the rest.

One gap left in `mailerIsFake()` worth knowing about: it reads `mail.default` and never the legs of
a `failover` mailer, so `MAIL_MAILER=failover` over `[smtp, log]` would pass every guard and quietly
write reset links to a log file. Nothing uses it, and the stock `failover` block is still in
`config/mail.php`.

## Limits that constrain what you may safely do

Resend's acceptable-use policy requires a **bounce rate under 4%** and a **spam rate under 0.08%**,
over which an account "may be shut down without warning". For scale, the SES thresholds this
replaced were 5%/10% and 0.1%/0.5%, with a notification and a review period first. Resend documents
no volume floor and no smoothing window, so at this app's volume **one hard bounce is 4%**.

The free plan allows 3,000 messages a month and a hard **100 a day**; crossing the daily cap pauses
sending rather than billing.

Both point at the same rule. Of the three mails, only verification goes to an address nobody has
confirmed — so **a typo at registration is very nearly the whole bounce risk**, and the one thing
never to do is re-send verification to a backlog of stuck hosts in one go.

## What is not wired up

**Bounce and complaint visibility.** Under SES this was notifications pointed at a monitored inbox.
Resend has no email-me-the-bounces equivalent: the mechanism is a webhook, and this app has no
webhook endpoint of any kind. So a hard bounce today puts the address on Resend's **account-wide
suppression list** — where every later send to it is accepted and silently dropped — and nothing in
the app or in any inbox says so.

Until there is an endpoint, the suppression list is readable over the API, which is the cheap
version of the same information:

```
curl -s https://api.resend.com/suppressions -H "Authorization: Bearer $RESEND_API_KEY"
```

Dashboard logs keep each message's fate for 30 days. An agent with the Resend MCP connected can
read both without handling the key.

**DMARC.** `_dmarc.quikbooth.com` is not published, and is not required at this volume — a verified
Resend domain already passes SPF and DKIM. `v=DMARC1; p=none; rua=mailto:...` would add aggregate
reports on who is sending as this domain, at no delivery risk. If you do publish it, leave alignment
relaxed (the default): the From is on the apex while the Return-Path is on `send.quikbooth.com`, and
`aspf=s` would break SPF alignment and leave DKIM carrying it alone.

## There is no rollback, on purpose

SES support came out of the app on 2026-09-05, the day after the move landed. The only rollback it
offered was `MAIL_MAILER=ses` back onto a sandboxed account, which reaches addresses that are
themselves verified identities and nobody else — so it restored the operator's inbox and not the
product. A new host still could not receive a verification link, which is the entire reason the move
happened. Against that it carried a live footgun: `MailManager::addSesCredentials` injects
credentials only when *both* key and secret are non-empty, so a half-set environment fell back to
the ambient AWS credential chain, picked up the photo bucket's `AWS_*` pair — which has no
`ses:SendEmail` — and failed as an AccessDenied inside a queue worker rather than at boot.

**The AWS side is deliberately untouched.** `quikbooth.com` is still a verified SES domain identity
in `ap-southeast-2` with its DKIM records published. SES allows 10,000 identities per region at no
cost, it does not interfere with Resend (different DKIM selectors, and Resend's SPF lives on the
`send` subdomain), and keeping it means re-adding SES is a `ses` block in each of `config/mail.php`
and `config/services.php` plus three variables — a deliberate ten minutes rather than a trap left
armed. `aws/aws-sdk-php` stays installed regardless, because `league/flysystem-aws-s3-v3` requires
it for the photo bucket.

**What actually covers a Resend outage is the queue.** All three mails are `ShouldQueue`, so while
Resend is down or the account is suspended, jobs retry rather than vanish — a password reset
requested during an outage arrives late instead of never. That was already true and is the real
mitigation. If it stays down long enough to exhaust the retries, the failed jobs are in the Cloud
Queues dashboard.

## Why Resend (2026-09-04)

AWS refused this account's request to leave the SES sandbox. A sandboxed transport delivers only to
addresses that are themselves verified identities, which had a sharper consequence than it sounds: a
new host could register and log in, but the verification mail never arrived, and verification is
what gates `/new`. She reached her dashboard and could not create an event, with no way out from the
UI.

Resend was picked because it has **no approval gate at all** — no sandbox, no review, no waiting
period — so the only thing between a new account and real delivery is DNS you already control. It is
free at this volume and, because Laravel 13 ships the transport, costs one package.

Two things about the trade that are easy to forget and expensive to re-learn:

- **Resend sends via SES.** Its subprocessor list names AWS as a sending provider, its regions are
  AWS region ids, the MX record it asks for is `feedback-smtp.us-east-1.amazonses.com`, and sent
  messages carry `@email.amazonses.com` message ids. This app did not leave SES — it stopped needing
  its *own* standing on it. Do not record the move as "we're off AWS".
- **The limits got tighter, not looser** — see above. The approval gate went away; the ongoing
  thresholds are stricter and lost their warning stage.

The alternatives, briefly, in case this is ever re-opened: Postmark and MailerSend both have their
own human approval gates, which is the same wall, and Postmark is the only option not free at this
volume. Mailgun needs a card for arbitrary recipients, and its no-card path requires the *recipient*
to accept an invitation — a circular dead end for a host who cannot receive mail in the first place.
SMTP2GO was the real runner-up and the only one offering Sydney-region sending, but has no
first-party Laravel driver; if data residency ever becomes the deciding factor, start there. (Resend
stores all account data in the United States regardless of sending region.)

## Standing up a sending domain again

Only needed for a new environment, a second brand, or a re-add after a mistake — `quikbooth.com` is
already done. The order matters: the deploy gate catches a transport that will not build and a
blank key, but it cannot see an unverified domain or a rejected key, so steps 3 and 4 are checks you
have to make yourself.

1. **Add the domain** in Resend, region `us-east-1`, tracking off (the domain's **Configuration**
   tab). Add the apex, not a `send.` subdomain: Resend recommends a subdomain to isolate sending
   reputation, which is a real argument, but verifying one as the *identity* changes what recipients
   see in the From line. Resend puts its Return-Path on `send.<domain>` either way, invisibly.

2. **Publish the DNS records Resend shows you** — do not pre-author them from this file, because the
   shape varies. `quikbooth.com` came back in the legacy form:

   | Type | Name | Value | Priority |
   |---|---|---|---|
   | TXT | `resend._domainkey` | the DKIM value from the dashboard, verbatim | |
   | MX | `send` | `feedback-smtp.us-east-1.amazonses.com` | 10 |
   | TXT | `send` | `v=spf1 include:amazonses.com ~all` | |

   Names are relative to the zone — Cloudflare appends the domain, so enter `send`, not
   `send.quikbooth.com`, or you get `send.quikbooth.com.quikbooth.com`. TTL **Auto**. The DKIM value
   starts `p=` and is one unbroken string: do not prepend `v=DKIM1; k=rsa;`, do not add quotes, do
   not trim the trailing `=` padding.

   None of those three record types is proxiable, so there is no grey-cloud trap on them. Resend's
   docs note that domains created after August 2026 may instead be shown **CNAME** records — if you
   see CNAMEs, they must be set to **DNS only** (grey cloud) on Cloudflare, because proxied records
   don't resolve as CNAMEs and verification then never completes, with no error anywhere.

   Adding a Resend domain does not disturb an existing SES identity. The DKIM selectors differ
   (`resend._domainkey` against SES's random tokens, and DKIM permits any number of them), and
   Resend's SPF sits on the `send` subdomain rather than the apex, so it neither conflicts with an
   apex SPF record nor spends any of its ten-lookup budget.

3. **Wait for *Verified*.** Each record must verify individually — a domain can sit at
   `partially_verified`, which still sends but without a fallback sending server. Use Resend's
   **Verify DNS Records** action rather than waiting on propagation. To see what the world actually
   resolves: `nslookup -type=TXT resend._domainkey.<domain>`, and the same for the two `send`
   records. Flip `MAIL_MAILER` before this and Resend answers 403 `The <domain> domain is not
   verified. Please, add and verify your domain.` for **every** recipient, your own included — there
   is no equivalent of the SES sandbox's verified-identity escape hatch.

4. **Confirm the deployed release carries the package.** From the Commands tab,
   `php artisan tinker --execute="var_dump(class_exists('Resend'));"` must print `bool(true)`. The
   build is `composer install --no-dev` from the *committed* lock, so a lock without the package
   means a worker fatal, and `photobooth:check-mail` will not tell you.

5. **Create an API key**: permission **Sending access**, restricted to the domain so it cannot send
   as anything else.

6. **Set the four variables** and **redeploy**, so `config:cache` re-reads
   them. `RESEND_API_KEY` must exist *before* that deploy or `services.resend.key` stays null.

7. **Prove it.** `photobooth:check-mail --to=<a-real-mailbox-you-can-open>`, to a real Gmail address
   and a real Outlook address, neither your own nor on the sending domain, because a shared pool is
   judged per recipient domain. **Never an invented address** — that is a guaranteed hard bounce,
   and one bounce is 4%. Then request one real password reset from `/forgot-password`, because the
   probe uses `Mail::raw` and sends synchronously while every real mail is queued.
