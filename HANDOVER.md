# Handover — Quikbooth

Orientation for the next agent picking this up. **This file is the short version**: what the app
is, how to run it, the conventions you must follow, and what is left to build. The detail lives in
its siblings.

| Doc | What it holds | Read it when |
|---|---|---|
| **[ARCHITECTURE.md](ARCHITECTURE.md)** | How the app is put together and **why** — routing, the paged album, privacy and retention, the durability and mail guards, download-all, the client modules | Before changing anything server-side |
| **[GATE.md](GATE.md)** | The real-device checklist (Android + iPhone) that everything in P2 is queued behind | Before P2, and whenever you ship something a phone has never rendered |
| **[PLAN.md](PLAN.md)** | Locked product decisions, the roadmap, and the design system | Before changing a screen or re-opening a settled decision |
| **[DEPLOY.md](DEPLOY.md)** | Laravel Cloud: env, build/deploy commands, the queue, the scheduler | Before deploying or touching infrastructure |
| **[MAIL.md](MAIL.md)** | The three mails, the Resend transport, the sending limits that constrain what you may send, and what bounce visibility is missing | Before sending anything to more than one person, or when a mail didn't arrive |
| **[OBSERVABILITY.md](OBSERVABILITY.md)** | The error-tracking / monitoring plan (Phill's, mostly not built) | When production breaks and nobody told us |
| **[README.md](README.md)** | Local quickstart | First run on a new machine |

> **These are not write-once.** Every one of them describes behaviour that a commit can invalidate.
> When you change something, update the doc that describes it **in the same commit** — the same way
> the work list below is only ever what is left. Which doc: behaviour and reasoning go in
> ARCHITECTURE, anything a phone must be checked against goes in GATE, infrastructure goes in
> DEPLOY, a settled product decision goes in PLAN, and the status line, conventions, work list and
> handy facts stay here.

## What it is

An event photobooth **web app** (no install). A guest scans a QR / opens a link, takes a set of
photos, watches them compose into a photo strip **on their phone**, and shares them to the event
album. Hosts create and manage events behind a login; guests just need the event code.

## Status — LIVE

Deployed and running on **Laravel Cloud** (Serverless Postgres 18, S3-compatible object storage
for photos/logos). Feature-complete through: MVP → hardening → richer booth (templates, branding,
filters) → owner accounts + admin oversight → **full redesign** (every screen rebuilt to the Claude
Design canvas `Redesign.dc.html`; see PLAN.md "Design system" + "Redesign") → **P0 safety
hygiene** (noindex/robots, the friendly unknown-code 404, a throttled register) → **P1 safe
pipes** (typed upload failures, a jittered offline-aware retry tail, an interrupted share that
resumes itself from IndexedDB, queued album thumbnails + a tap-to-enlarge lightbox, immutably
cached session-free image routes) → the first of **P3 host trust** (a host can delete an event,
and everything behind it, without an SSH session) → **a paged album** (the 4000-photo event that
used to render 3997 `<img>` tags into one page now arrives 24 sessions at a time, and the dev
seed can produce that event on demand) → **an album a host controls**: tri-state privacy
(open / PIN / hidden) and a stated retention window that expires the album, then sweeps its photos
thirty days later on a schedule → **a host account that can look after itself**: password reset
and email verification over a real mail transport (Resend), behind the same kind of deploy gate the
storage disk has → **download-all**: a queued job zips a whole night into one file and emails the
host a signed, expiring link.
**322 Pest + 83 Vitest tests green.** Every feature slice was built red/green and then put
through an adversarial review (see Conventions).

## Stack & how to run

- **Laravel 13 + Pest 5** (PHP 8.4), **vanilla TypeScript + Vite** (no UI framework, no Tailwind —
  a hand-rolled design system lives in `resources/views/partials/theme.blade.php`).
- **SQLite locally, Postgres in production.** The code is DB-agnostic (Eloquent + portable
  migrations; the one raw fragment is the album cursor's `MAX(id)` / `HAVING`, which is ANSI and
  runs the same on both).
- Dev commands: `composer run setup` (first run), `composer run dev`, `php artisan test`,
  `npm test` (Vitest), `npm run build`.
- **Phone testing needs HTTPS** (camera APIs require a secure context): run a cloudflared tunnel at
  the dev server and open the printed URL on the phone. Quick-tunnel URLs change on every restart,
  so don't print QR codes against them.

### Windows gotchas (this machine)
- **PHP/Composer aren't on PATH.** They live in the winget package dir; prepend it, e.g.
  `$pkg = "$env:LOCALAPPDATA\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe"; $env:Path = "$pkg;$env:Path"`.
- **Git commit messages: use `git commit -F <file>`.** PowerShell 5.1 mangles apostrophes/quotes in
  `-m @'...'@` heredocs (splits the message into bad pathspecs). Write the message to a scratch file
  and commit with `-F`.

## Architecture map

Moved out: **[ARCHITECTURE.md](ARCHITECTURE.md)** carries the whole map — routing and the two route
files, the paged album's cursor, the session-free image routes, album privacy and the retention
window, the durability guards, the mail guards, download-all, and the client modules. Read it
before changing anything server-side; almost every non-obvious decision in this app has its reason
written down there rather than in the code.

**If you change how something works, change that file in the same commit.**

## Deployment

`DEPLOY.md` has the full Laravel Cloud checklist. The one that bit us: with no DB attached the app
falls back to the SQLite default and dies with "database.sqlite does not exist" — attach Postgres,
**redeploy** (so `config:cache` re-reads env), then `php artisan migrate --force`. Also set
`FILESYSTEM_DISK=s3` (durable photos) and `APP_DEBUG=false`. Production starts with **no data**
(the demo seed is `local`-only); register, then `php artisan photobooth:make-admin you@example.com`
to grant yourself admin. **New with P1: the environment needs something running the thumbnail
jobs** — production uses a Laravel Cloud managed queue (which is why `aws/aws-sdk-php` is a
dependency); nothing breaks without one, every guest just pays for it in bandwidth.
`composer run dev` runs a worker locally.

## Conventions (the user's, follow them)

- **Red/green TDD** for new features. **When a bug is reported, write a failing test that
  reproduces it first**, then fix.
- **Simple, skimmable code**; early returns; no enterprise abstractions; **not defensively coded
  early** (harden after it works); **no fallbacks** — forward-thinking tested solutions.
- **Adversarial review after each slice**: the established rhythm is to build a slice, then run a
  Workflow that fans out review "lenses" and has a second agent try to *refute* each finding before
  it's accepted, then fix the confirmed ones. It has caught a real bug in almost every slice — keep
  it up for anything non-trivial.
- **Keep the work list honest**: strike an item from "What's next" as soon as it ships, in the
  same change — that list is only what's left. Finished work lives in the Status line,
  [ARCHITECTURE.md](ARCHITECTURE.md) and the git log.
- **Keep the sibling docs honest too**, in the same commit as the code. A slice that changes how
  something works updates [ARCHITECTURE.md](ARCHITECTURE.md); one that adds a screen or a state a
  phone has never rendered adds it to [GATE.md](GATE.md); one that changes deployment or
  configuration updates [DEPLOY.md](DEPLOY.md). A doc that lies is worse than no doc, and the
  reasoning in these files is the only record of why this app is the way it is — every non-obvious
  line of it was paid for by a bug, a measurement or a real event.
- **Never commit unless Phill asks**, and ask again next time — permission for one batch doesn't
  carry. Finish the slice, leave it in the working tree, and offer a commit split.
- **When he does ask, branch first — feature work never lands straight on `main`.** Cut a branch
  named for the slice (`p3-event-delete`), commit there, and hand back the fast-forward, so
  whether it reaches main stays his call and he makes it having already seen the commits:
  `git checkout main && git merge --ff-only p3-event-delete`.
  Shape the commits the same way each time. **One commit per slice** — not one per file, and not
  one for everything: the git log is this project's per-slice narrative, so a commit is a thing
  that shipped, and the message explains the *why* rather than restating the diff. **Check each
  commit is green on its own** by checking the intermediate out and running the suite; a split
  nobody can bisect to is worth less than no split at all. And **split the `HANDOVER.md` edits
  across the commits** so each one's doc describes only its own code — the test count included,
  which means the earlier commit reads the lower number. Write messages with
  `git commit -F <file>` (see Windows gotchas).
- Verify in the browser (the in-app Browser pane) by reading actual output, not by asserting it
  "should" work — e.g. read a composed strip's pixels, don't assume.

## What's next — prioritised work list

Nothing is broken or half-done. This list came out of the 2026-08-26 competitive review
("State of the Booth" — https://claude.ai/code/artifact/63d587ad-5a7a-4bd5-9b8e-6a89a1dacca0,
ask Phill for access; it carries the full rationale and feasibility notes). Work the phases
top to bottom; **items within a phase are independent and can be picked up in parallel.**
Each item is a slice: red/green TDD, then an adversarial review pass (see Conventions).
**Delete an item from this list when it ships** — the list is only what's left, and the Status
line plus the git log are where finished work is recorded. Item numbers are stable IDs from the
review, so they don't get renumbered when something above them goes; a phase whose items are all
done disappears with them.

Alongside the phases, not from the review: **[OBSERVABILITY.md](OBSERVABILITY.md)** (Phill's,
2026-09-01) — the error-tracking/monitoring plan. Its phases 0–1 are dashboard/config work, not
slices; its phase 2 (the booth reporting its own client errors) is a normal code slice and can be
picked up like any item here.

### Gate — one combined real-device pass (Android + iPhone), before P2

Everything below is queued behind one session on real phones. The full checklist is
**[GATE.md](GATE.md)** — every screen to try, in the order it makes sense to try them, with the
seeded event codes for each state.

**Add to it whenever you ship something a phone has never rendered.**

### P2 — Participation engine (the visible payoff)
9. Live wall: full-screen `/e/{code}/wall` for venue TVs — strips animating in via 3–5s
   cursor polling, event QR + code always in a corner, Screen Wake Lock, watchdog reload when
   polls stall (tab-sleep is the #1 documented live-wall failure).
10. Moderation shipped WITH the wall: approve/hide per session from the host's phone, pending
    count visible on the wall page. (Host-uploaded sponsor slides between strips: follow-up.)
11. Photo missions: host-picked prompt packs; a mission deep-links into capture and stamps
    the prompt as the strip caption.
12. "My strips tonight": a localStorage device token groups a returning guest's sessions;
    optional email-me-my-strip field with separate consent checkboxes (delivery ≠ marketing).

### P3 — Host trust pack (before charging money)
20. Warn hosts before their retention window closes — a mail at, say, 14 days and 1 day out, and
    one when the photos have actually gone. **Phill's, 2026-08-31, not from the review**, and the
    reason the 90-day default is safe to ship without it only for as long as nobody has had an
    album swept: right now the window is stated on three screens and nowhere else, so a host who
    never opens the app never hears about it. `photos_purged_at` already records the fact the last
    of those three mails would report.
21. Bounce and complaint visibility. Resend's equivalent of the old SES-notifications-to-an-inbox
    arrangement is a webhook (`email.bounced`, `email.complained`, `email.failed`,
    `email.suppressed`), and there is no endpoint for one — so a hard bounce puts an address on
    Resend's account-wide suppression list, every later send to it is accepted and dropped, and
    nothing says so. The list is readable over the API in the meantime; MAIL.md has the check.
    **Deferred 2026-09-04** — nothing has bounced yet, and at three mails a week the API check is
    cheaper than the endpoint.
22. Give the verification gate a third state, and a host a way to fix their own address.
    `Deliverability::mailerIsFake()` tests the mailer *name*, so the app knows "no mailer" and "a
    mailer" and nothing in between. A real transport that will not deliver to one recipient — a
    suppressed address, a suspended key, an exhausted quota, or simply a typo at registration —
    lands a host exactly where the SES sandbox did: dashboard reachable, `/new` barred, no way out
    from the UI. `/email/verify` says "ask an admin to change it" and no route exists that lets
    them. Two halves worth shipping together: let a host correct an unverified address, and let the
    gate degrade rather than bar. A client-side domain-typo suggestion at registration (edit
    distance against the common providers, *suggest* never block, and include the AU ones —
    `bigpond.com`, `optusnet.com.au`, `iinet.net.au`) reduces how often it happens; the other two
    remove the consequence. **Deferred 2026-09-04.**

### P4 — New output modes (independent slices, in effort order)
17. 9:16 story-strip variant of every layout from the same frames (share-sheet ready; build
    the blob before the tap so iOS keeps the user gesture).
18. Boomerang/GIF: MediaRecorder with `isTypeSupported` probing (iOS was MP4-only pre-18.4),
    gifenc-in-a-Web-Worker as the universal fallback. The encoding research is done — recipe
    in the report.
19. Audio guestbook, then video guestbook (video needs chunked uploads + server transcode).

### P5 — Strategic bets (Phill picks the direction first)
- **Consumer**: template/frame library as versioned data (not code paths), AI portrait strips
  (server-side queued jobs; never meterable mid-event), delayed album reveal, event cover photo.
- **B2B**: data-capture fields at the share step, host analytics dashboard, white-label +
  iframe embed.
- **Enablers when needed**: WebGL2/LUT capture pipeline (`ctx.filter` is confirmed never
  coming to iOS), on-site/mail-order printing via PrintNode/Prodigi (2×6 SKU unverified).

### Anytime fillers (fit between any two phases)
Filter-change-at-review (keep raw frames, apply the colour matrix at compose) · back-camera
toggle + higher capture resolution · audio/haptic countdown cue.

### Explicitly not doing
- **Per-shot retakes** — product decision (2026-08-26): the booth models a real photobooth,
  and a real booth doesn't let you retake a frame. Don't "fix" the all-or-nothing retake.
- Face search (AU Privacy Act sensitive-information obligations; low value for a booth).
- Admin impersonation — revisit only when third-party owners need hands-on support.
- Event cover photo now — folds into the P5 consumer/branding bet if that direction wins.
- 360/glambot (hardware), PWA / Web Push as primary delivery, in-gallery comments,
  multi-language (until a market asks).

## Handy facts
- Dev login (local only): `demo@example.com` / `password` (seeded admin).
- **`php artisan db:seed` gives you albums to look at**, not just the login: an empty booth to
  shoot into (`PARTY2`), one session (`BREKKY`), and a normal closed night of twelve (`GARDEN`).
  `--class=BigEventSeeder` adds the two that are worth measuring against — `SUNSET` (750 photos)
  and `NEWYRS` (4000, the night that stalled a dev server) — in ~15s and ~280MB. Seeded photos
  are real JPEGs with real derivatives, written where `PhotoController::store` and
  `GenerateThumbnail` write theirs, because an album's cost is the files it asks for; only a
  dozen images are ever drawn and the rest are copies of those (`SeedsAlbums`). It skips any
  event that already has photos, so re-running it is safe. It also seeds the three album states
  that are otherwise a chore to reach by hand, because none of them can be produced by shooting
  into a booth: `SECRET` (PIN `bridesmaids`), `LAPSED` (expired, still inside its grace period, so
  the host sees the countdown) and `SWEPT2` (photos already deleted — empty on purpose).
- **Mail locally goes to `storage/logs/laravel.log`** (`MAIL_MAILER=log`, and `local` is exempt
  from the mailer guard). A reset or verification link is one grep away:
  `grep -o 'http://localhost:8000/[a-z/-]*verify[^"< ]*' storage/logs/laravel.log`. Clear the log
  first or you will find an older one.
- **The queue matters more now.** `composer run dev` runs a worker; `php artisan serve` on its
  own does not, so thumbnails and download-all archives will sit in the `jobs` table untouched.
  `php artisan queue:work --once` clears one by hand.
- Event codes: 6 chars from an unambiguous alphabet (no O/0/1/I), case-insensitive.
- Recent git history is the best per-slice narrative — each commit message explains the why.
