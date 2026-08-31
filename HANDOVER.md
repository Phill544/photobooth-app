# Handover — Photobooth

Orientation for the next agent picking this up. For depth, read alongside
[PLAN.md](PLAN.md) (decisions + roadmap + design system), [DEPLOY.md](DEPLOY.md) (Laravel Cloud),
and [README.md](README.md) (local quickstart). This file is the map and the working conventions.

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
and email verification over a real mail transport (SES), behind the same kind of deploy gate the
storage disk has.
**290 Pest + 83 Vitest tests green.** Every feature slice was built red/green and then put
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

**Routing** splits cleanly across two files:
- **Guest, public (no login), `routes/web.php`:** `GET /e/{code}` (capture), `/e/{code}/gallery`,
  `POST /e/{code}/gallery/unlock` (throttled), `POST /e/{code}/photos` (throttled, CSRF-exempt).
  The event code is the credential.
  **The album has a front door** (`EventController::albumGate()`): a host or admin is never turned
  away, and a guest meets whatever `events.album_privacy` says — `open` (what every album that
  existed before this had, and the default), `pin`, or `hidden`. Hidden is a refusal and answers
  403; a PIN is a door, so it is a 200 with a form in it, and the unlock is a session flag keyed
  per event (a guest can be at two). Expiry outranks both: a guest holding a PIN that would open
  nothing is told the album is over rather than asked to type it. Both sides of the gate carry the
  page of the album the guest was on, rebuilt from `order`/`after` server-side rather than echoed,
  so the only place an unlock can ever redirect to is this album. **The PIN gates the album page
  and nothing else** — the image routes stay session-free and immutably cached exactly as P1 left
  them, so a leaked photo URL still opens (Phill's call, 2026-08-31, pinned by a test). The PIN is
  stored in the clear, because the host reads it out to a room and has to be able to read it back;
  it sits beside the event code, also in the clear, guarding the same album. Its bounds live once
  on `Event::PIN_MIN_LENGTH`/`PIN_MAX_LENGTH` — the guest's field is the one that silently
  truncates typing *and* paste, so a literal there that drifts below the validator is a PIN the
  host can set and no guest can enter.
  **The album is paged, and a page is a page of *sessions*** (`EventController::SESSIONS_PER_PAGE`,
  24) — a strip and the shots it was composed from are one card, so half a session is not a thing
  the page can render. The cursor is `?after=<MAX(id)>`, the session's place in the night, rather
  than a row offset: offsets would hand a guest scrolling a live album the same card twice as soon
  as somebody else shared. `MAX()` + `HAVING` is the portable spelling of that in both SQLite and
  Postgres, and the grouped query is the only aggregate SQL in the app. `?order=oldest` reverses
  it (the flip is a link now — a client-side one could only reorder the page it can see), and the
  header's counts come from two `count()` queries, because they speak for the whole album. The
  empty state answers *is this album empty*, never *is this page* — a cursor can outlive the sessions
  behind it (a host deleting the last one), and that page is the end of the album, not an empty
  one. The page's foot is a real `<a id="more">` to the next cursor, which the album's own script follows
  on approach (`IntersectionObserver`, 600px early) and on tap, appending both panels out of the
  fetched page. With no JS it is simply a link. **Measured on the 4000-photo event: 97 `<img>`
  tags and 69KB against 3997 and 1.6MB, 96 rows hydrated against 3996 (50MB peak), 3ms of query
  against 92ms.**
- **Images, `routes/images.php`:** `/e/{code}/logo`, `/e/{code}/photos/{photo}` and `.../thumb`,
  registered from the `then:` closure in `bootstrap/app.php` with **only `SubstituteBindings`** --
  deliberately outside the `web` group, because an album asks for dozens of immutable files at once
  and not one of them needs a session, a CSRF token or a cookie. All three answer through
  `App\Support\ImageResponse::immutable()`: a year of **`private`** caching (never `public` — an
  album is only as private as its code, and a deleted session must not live on in a shared cache),
  an ETag over the stored path, `X-Robots-Tag: noindex`, and a 304 when the phone already has the
  bytes. Verified to survive the `route:cache` the deploy runs, and the unknown-code 404 still
  names the code from these session-free routes. **A row that outlives its file answers 404, not
  500** — Flysystem raises `UnableToRetrieveMetadata` from `Storage::response()` while sizing the
  body, and an album asks this route once per tile, so the wrong answer multiplies: measured at
  1.2s/902KB per tile against 0.44s/21KB once it 404s. That state is reachable in production
  (DEPLOY.md's detached-bucket row) and is also the deliberate intermediate state of
  `Event::purge()`, which drops bytes before rows.
- **Owner, auth-gated:** `/dashboard`, `/new`, `POST /events`, `GET|PATCH|DELETE /events/{code}`,
  toggle-closed, privacy, retention, and `DELETE /e/{code}/groups/{group}`.
  `Event::managedBy($user)` = owner OR admin, else 403. The delete asks for the event code **in the request body**, not a browser `confirm()`:
  it is the one action that destroys every guest's photos, and a dialog guards nothing a request
  can skip. A rejected code redirects to `#delete` — the panel is the last thing on a long page,
  and the error was measured 270px below the fold without it.
- **Auth:** register/login/logout, forgotten-password and address-verification, all hand-rolled in
  `AuthController` and styled to the design system. **Reset**: a one-hour, single-use token; the
  request form gives one answer for an address it knows and one it does not, or it becomes a way of
  asking which addresses have accounts; a completed reset **ends every session that account had
  open**, because the usual reason for resetting is that somebody else has the old password — and
  that somebody is typically already signed in, where the session guard would otherwise keep
  re-authenticating them from the user id it holds and never look at the hash again. That is
  `$middleware->authenticateSessions()` in `bootstrap/app.php`, which compares a hash carried in the
  session rather than deleting session rows — so it works on the `cookie` driver production runs as
  well as on `database`. Rolling the remember token alone only revoked the cookie an intruder who
  simply logged in never used. The reset lands on `/login` rather than logging the host straight in.
  The reset pair also has **its own throttle bucket** (`throttle:6,1,reset`): an unnamed throttle
  keys on the IP, not the route, so without it six failed logins would 429 the one form that lets a
  host who has forgotten their password back in. **Verification** gates exactly one thing — `/new` and
  `POST /events` — so a typo'd address never costs a host the event they are already running, and
  the link is checked against the signed-in account rather than trusted for whoever opens it. Every
  host who existed before it shipped is grandfathered by a migration; only new registrations prove
  their address.
  **The mailer is the disk trap again** (`App\Support\Deliverability::mailerIsFake()`). Laravel's
  default is `log`, which accepts everything and delivers nothing, and Laravel Cloud injects a
  database, a disk and a queue but has no mail service to inject — so a page saying "check your
  email" over that default is the same silent failure that cost this app its first photos. Two
  guards, same shape as storage: `photobooth:check-mail` is a **deploy command** that fails a
  release whose mailer is fake or whose from-address is still the framework's `@example.com`
  placeholder (`--to=` also sends a real message, the only way to tell working config from working
  credentials), and at **request** time the forgot-password page says so plainly instead of offering
  a form, the endpoint behind it answers 503, and login stops linking to it. The verification gate
  lifts entirely when the mailer is fake — requiring a link nothing can send is a locked door with
  no key cut for it, and DEPLOY.md is explicit that a failing deploy command may not abort a
  release. `local` and `testing` are exempt from all of it, so a dev with no SES credentials still
  works: the link lands in `storage/logs/laravel.log`. **SES reads `SES_*`, deliberately not the
  `AWS_*` pair** the framework ships in `config/services.php` — those are this app's bucket, and one
  rotation should not be able to take out either photos or password resets with no visible
  connection between them.
- **Errors:** every 404 renders `resources/views/errors/404.blade.php`. A render hook in
  `bootstrap/app.php` fires only when an **`Event`** route binding is what failed and passes the
  code that was tried, so the page can name it; everything else (a missing photo under a real
  code, the JSON uploader) falls through untouched. The six-tile join form is
  `partials/code-entry.blade.php`, shared by that page and the home page — its styles live in
  `partials/theme.blade.php`, and its submit handler refuses a code that isn't six characters
  rather than spending a page load to be told the same thing.

**Client (`resources/js/`)** — pure, unit-tested logic vs dumb browser glue:
- Pure (Vitest): `capture-flow.ts` (the whole booth as a state machine), `strip-layout.ts` (grid
  geometry), `strip-compose.ts`, `templates.ts`, `strip-theme.ts`, `filters.ts` (CSS strings +
  colour matrices), `upload-queue.ts`, `in-app.ts`, `pending-session.ts` (the IndexedDB store,
  tested for real against `fake-indexeddb`).
- Glue (device-tested): `camera.ts` (getUserMedia + filtered frame grab), `capture.ts` (wires the
  state machine to the DOM), `wake-lock.ts`, `strip-preview.ts` (live preview on create/edit forms),
  `upload.ts`.
- **A failed upload is a branch, not a message:** `upload.ts` turns a refused upload into a typed
  `UploadError` — `closed` (410), `throttled` (429, honouring `Retry-After`), `rejected` (422) or
  `network` — and `upload-queue.ts` decides from that: terminal kinds stop at once, the rest get a
  jittered 1s/3s/8s/20s tail. It also holds (bounded, `OFFLINE_HOLD_MS`) while `navigator.onLine`
  is false rather than spending attempts on a dead radio — bounded because the uploading screen is
  the one screen with no way to save a strip, so the queue must always be able to end. The failed
  screen's copy comes from the reason **and** the landed count: the strip is queued first, so one
  landed file means it is already in the album and the screen must not say otherwise.
- **The booth holds the only copy until it lands**, so two things it must never do: reach a screen
  that hides the Save link while holding an unsaved strip (a `toJpegBlob` rejection used to escape
  to the global handler and the terminal error screen — `shareToAlbum` now catches its own
  failures and stays on review), and delete a pending session it has not tried to send (a session
  past its 24h window still gets one last attempt when the guest opens that booth again; only
  sessions from events they are *not* at are swept).
- **An interrupted share finishes itself:** tapping Share writes the session (blobs, group uuid,
  event code) to IndexedDB before the first byte goes up; the next load of that booth drains
  whatever is left in the background and narrates it in `#resume-notice`. Records expire after 24h,
  and are dropped after a terminal failure. The store is a safety net, never a dependency — a
  device that will not give us one (private-mode Safari) still shoots, shares and saves.
- **Filters are the subtle bit:** each filter is one op-list → a CSS string (live preview + the
  Chrome `ctx.filter` fast path) AND a 4×5 colour matrix. iOS Safari ships `ctx.filter` disabled, so
  `grabFrame` feature-detects it and falls back to a `getImageData` colour-matrix pass — verified to
  match the CSS path within 1–2/255.

**Durability (the app's most expensive lesson).** Photos live in Laravel Cloud object storage;
the container's own filesystem is wiped on every deploy. Before a bucket was attached the default
disk silently fell back to `local` and every photo written was lost, with no error anywhere — so
two guards now exist, and neither is optional. `App\Support\Durability::diskIsEphemeral()` is asked
**per upload request** (a bucket can be detached, a preview environment gets none, a worker
container can lack the injected config) and refuses the write with a 503, which the client retries
and the phone survives. `php artisan photobooth:check-storage` asks the same question as a **deploy
command**, plus a write/read round trip, so a release configured that way should not go live at all;
`--photos` adds the after-the-fact question (how many photo rows point at a file that isn't there),
which is the only way anyone would find out that something had already gone.
Two related traps, both found by the audit and both now covered: the disk is built with
`'throw' => false, 'report' => false`, so a refused write returns a bare `false` with nothing logged
— `PhotoController::store`, `applyLogo` and `GenerateThumbnail` all check that return rather
than recording it (a
`false` path would mean a 201 for bytes that do not exist, and the booth drops its own copy on a
201); and the logo is written before the old one is deleted, never the other way round.

**Server** — `EventController` (create/manage/dashboard/logo/QR), `PhotoController` (upload +
idempotent per `(event_id, group_uuid, slot)`, serve, serve derivative, session delete),
`AuthController`. Every upload dispatches `GenerateThumbnail`, the first thing here to use the
queue: raw GD in `App\Support\Thumbnail`, 480px wide, written beside the original and recorded on
`photos.thumb_path`. `Photo::gridUrl()` asks for the derivative once there is one and the original
until then, so an unrun queue degrades to yesterday's behaviour instead of broken images — **but
production needs a worker process** (DEPLOY.md has the command). `Photo::paths()` is the single
place that knows a row owns two files, so a **session** delete cannot orphan a derivative.
Deleting a whole **event** goes through `Event::purge()` instead — the one place that knows an
event's files, shared by the owner's delete button and `photobooth:purge-event` (which now takes
`--force`, so it can be scheduled). It sweeps `events/{id}/` by prefix rather than a path at a
time, for two reasons: `Storage::delete()` costs an object-store round trip **per file** and a
busy night is thousands of them (measured: a 4000-photo event, ~4000 sequential calls, against a
request that has a gateway timeout — the prefix goes in batches of a thousand); and it is the only
way to catch a derivative `GenerateThumbnail` wrote before it recorded the column, which no row
names. That is correct only while every photo is written under that prefix, which
`PhotoController::store` does and `EventDeleteTest` pins with a test. The logo is deleted by
path — logos are not per-event, and `photobooth:purge-event` used to leave every one behind. Strip
**layout/shot-count** and **colour themes** live as data in `Event::TEMPLATES` / `Event::STRIP_THEMES`
(PHP, for the form + validation) mirrored by geometry/hex in `templates.ts` / `strip-theme.ts` (JS,
for the canvas) — **keep the keys in sync by hand** (noted in both files).

**Retention.** `events.photos_expire_at` is a stated window — `Event::RETENTION_DAYS` (90) on a
new event, set in `booted()` rather than backfilled by the migration, because only events created
after the window existed have guests who were told about one; every album that predates it is kept
for good. Guests are told the date twice, at the two moments it matters: on the review screen as
they decide to share, and in the album header. When the date passes, the album becomes an expired
page for guests, the booth stops taking photos (a photo shared into an album already counting down
is one the guest loses within the month), and the host keeps full access — which is the point of
`Event::PURGE_GRACE_DAYS` (30). Inside that gap a host or admin can move the date and the album
comes straight back, which is the "someone emails asking nicely" case the window exists for.
`photobooth:sweep-expired` then runs `Event::purgePhotos()`: the same prefix delete as `purge()`,
but the event row stays so its code keeps explaining itself, and the host's logo stays because a
logo is branding, not a guest's photo. It stamps `photos_purged_at` — **recorded, not inferred
from an empty album**, because a host who deleted every session by hand has not been swept, and
because after it is set no date brings the photos back, so `hasExpired()` reads it too and no date
reopens the album either. Every screen that used to label two states now asks `Event::status()`
for one of three: a closed booth and a finished one are not the same thing to say.
`photos_purged_at` is deliberately not mass-assignable — nothing a request sends may claim an
album's photos were deleted, because that claim shuts the album. **The sweep does nothing without
a scheduler**: DEPLOY.md has the toggle, and `->onOneServer()` is on the schedule because Cloud
runs it on every replica.

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
  same change — that list is only what's left. Finished work lives in the Status line, the
  Architecture map and the git log.
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

### Gate — one combined real-device pass (Android + iPhone), before P2
Everything below is queued behind one session on real phones. It covers the redesign screens
(HUD, looks thumbnails, tile code entry), the iOS filter fallback and countdown pacing, and now
the P1 paths too: each typed failure screen (close the booth from another phone mid-upload), the
offline hold and its hint, the resume notice after killing the tab mid-upload, IndexedDB in iOS
Safari **including private mode**, and the album's thumbnails, lightbox and per-photo save on both
platforms.

Added by the privacy/retention slice — all of it is new UI a phone has never rendered, and the
dev seed now carries every state (see Handy facts), so this is a walk through five codes:

- **The PIN gate** (`SECRET`, PIN `bridesmaids`): typing a *word* into the field on both keyboards
  — that `autocapitalize="off"` actually holds on iOS, that the whole 11 characters go in (a
  `maxlength` that disagreed with the validator was the bug that shipped and got caught), the wrong-PIN
  error, and that the unlock survives backgrounding the tab.
- **A hidden album**: switch `PARTY2` to "Only me" and check the booth offers no album link on
  either platform — and that the consent line above Share says *only the host* before you tap it.
- **The expired booth and album** (`LAPSED`), and **a swept one** (`SWEPT2`): both are full-screen
  dark type with no controls, which is the one screen shape nothing else on the phone exercises.
- **The review screen's second consent line** ("Photos are kept until …"). It fits at 375×812 in
  the pane, but that is the screen with the least room on a real short phone in landscape.
- **The host's two new folds** on a phone: the radio choices (they are prose, not swatches), and
  the `type="date"` field — iOS renders its own picker and this is the app's first date input.
  Check the summary line still reads as a state ("Photos · kept until 29 Nov 2026") at 375px.

And from the mail slice — small, but every one of these screens is reached **from a mail app**, so
the phone is where they are actually used:

- **The reset link opening in the phone's in-app browser.** That is a different browser from the
  one the host logs in with, so the form has to stand alone — which it does, but it has never been
  opened that way. Check the password managers offer to save the new one.
- **The verify link**, same journey, and that it lands on `/new` rather than the dashboard.
- The **`log` mailer means neither link will arrive at a phone** until SES is configured: grep
  `storage/logs/laravel.log` on the dev machine and open the URL on the phone by hand (the tunnel
  host will differ from `APP_URL`, so edit the host in the URL).

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
> **Mail is wired but not yet credentialled.** The transport, the guards, the deploy gate and the
> SES setup steps all exist now (see the Auth entry in the architecture map, and DEPLOY.md's "Mail"
> section). What is still outstanding is **Phill's**: verify a sending domain in SES, leave the
> sandbox, and set `MAIL_MAILER=ses` + `SES_KEY`/`SES_SECRET`/`SES_REGION` +
> `MAIL_FROM_ADDRESS` on the environment. Until that is done `photobooth:check-mail` fails the
> deploy, the reset pages say so honestly rather than promising an email, and verification gates
> nothing — so production is safe, it just cannot send.

14. Download-all ZIP: queued job streams S3 → zip, emails a signed expiring link. Never
    client-side (CORS + mobile memory). **Strips and originals in one archive, foldered**
    (Phill, 2026-08-31).
20. Warn hosts before their retention window closes — a mail at, say, 14 days and 1 day out, and
    one when the photos have actually gone. **Phill's, 2026-08-31, not from the review**, and the
    reason the 90-day default is safe to ship without it only for as long as nobody has had an
    album swept: right now the window is stated on three screens and nowhere else, so a host who
    never opens the app never hears about it. `photos_purged_at` already records the fact the last
    of those three mails would report.

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
- Event codes: 6 chars from an unambiguous alphabet (no O/0/1/I), case-insensitive.
- Recent git history is the best per-slice narrative — each commit message explains the why.
