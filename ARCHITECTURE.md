# Architecture — Quikbooth

How the app is put together, and **why** — almost every non-obvious decision here was paid for by a
bug, a measurement or a real event, and the reason is written down so the next person does not undo
it. Read this before changing anything server-side.

Its siblings: [HANDOVER.md](HANDOVER.md) is the map and the working conventions,
[PLAN.md](PLAN.md) holds the locked product decisions and the design system,
[DEPLOY.md](DEPLOY.md) is Laravel Cloud, [GATE.md](GATE.md) is the real-device checklist, and
[OBSERVABILITY.md](OBSERVABILITY.md) is the monitoring plan.

> **Keep this current.** If you change how something works, change the paragraph that describes it
> in the same commit. A map that lies is worse than no map: the comments in this codebase and the
> prose here are the only record of why things are the way they are.


**Routing** splits cleanly across two files:
- **Guest, public (no login), `routes/web.php`:** `GET /e/{code}` (capture), `/e/{code}/gallery`,
  `POST /e/{code}/gallery/unlock` (rationed inside the action, so only wrong guesses count — a
  changed PIN sends a whole room back through that door at once), `POST /e/{code}/photos`
  (throttled, CSRF-exempt).
  The event code is the credential.
  **The album has a front door** (`EventController::albumGate()`): a host or admin is never turned
  away, and a guest meets whatever `events.album_privacy` says — `open` (what every album that
  existed before this had, and the default), `pin`, or `hidden`. Hidden is a refusal and answers
  403; a PIN is a door, so it is a 200 with a form in it, and the unlock is a session entry keyed
  per event (a guest can be at two) holding a **fingerprint of the PIN it was bought with**, not a
  flag. That is what makes changing the PIN mean something: a host changes it because the wrong
  people have the old one, and a flag would have left every one of them inside — their only working
  lever being `hidden`, which shuts out the guests who should be there too. The fingerprint is
  normalised the way `pinMatches()` compares (trimmed, case-folded), so re-saving the same word in
  different capitals does not evict a room over a PIN that still opens the album; putting an old PIN
  back does readmit the guests who already typed it, which is the same door and the same key.
  Hiding an album evicts a guest who is already inside without consulting their unlock at all —
  privacy is one column, so `hidden` is simply not `pin` — which is why rotating the PIN, not
  hiding the album, is the lever for shutting one person out. Expiry outranks both: a guest holding a PIN that would open
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
  release whose mailer is fake, whose from-address is still the framework's `@example.com`
  placeholder, whose transport will not build, or whose API key is blank — the last two because
  naming a transport is not the same as having one, and both failures otherwise surface in a queue
  worker (`--to=` also sends a real message, still the only way to tell a working key from an
  accepted one) — and at **request** time the forgot-password page says so plainly instead of offering
  a form, the endpoint behind it answers 503, and login stops linking to it. The verification gate
  lifts entirely when the mailer is fake — requiring a link nothing can send is a locked door with
  no key cut for it, and DEPLOY.md is explicit that a failing deploy command may not abort a
  release. `local` and `testing` are exempt from all of it, so a dev with no Resend key still
  works: the link lands in `storage/logs/laravel.log`.
  **Every auth mail is queued** (`App\Notifications\QueuedResetPassword` / `QueuedVerifyEmail`,
  sent from `User`), and so is `ArchiveReady`. That is not tidiness, it is a production incident
  (2026-09-01): a new host registered, SES was still sandboxed, it refused the unverified
  recipient, and the `TransportException` escaped `event(new Registered(...))`. The account row was
  already written and `Auth::login()` had not run, so she got a 500, could not log in (she never
  learned the account existed) and could not register again (the address was taken). Sending on the
  queue means a transport that refuses a message can no longer take down the request that triggered
  it, and it keeps the forgot-password form's single answer honest, since only a real address ever
  reaches the transport. The same shape of bug sat in `BuildEventArchive`, which failed the whole
  job (rebuilding the archive on retry) and then parked a built, downloadable archive at `failed`
  when only the email had failed.
  `QueuedResetPassword` is additionally **`ShouldBeEncrypted`**, because queueing moves a live
  credential out of request memory and into a store: `ResetPassword` carries the RAW token while
  `password_reset_tokens` deliberately keeps only its hash, so an unencrypted payload would leave a
  working reset link in `jobs` and, on the very failure this design exists to survive, in
  `failed_jobs` and the Cloud Queues dashboard. A test greps the payload for any 64-hex run that
  `Hash::check`s against the stored hash.
  Two of the enumeration tests used to be tautologies: `TestResponse` has no `getSession()`, so
  `$response->getSession()` fell through to the app's single live session store and comparing two
  of them compared a value with itself. Read each flash straight after its own request.
  **Mail reads `RESEND_API_KEY`, deliberately not the
  `AWS_*` pair** the framework ships in `config/services.php` — those are this app's bucket, and one
  rotation should not be able to take out either photos or password resets with no visible
  connection between them. Resend replaced SES on 2026-09-04 — **[MAIL.md](MAIL.md)** carries that
  decision, the sending limits it brought with it, the rollback, and the one thing these guards
  still cannot see: they test the mailer's *name*, so a real transport that will not deliver to a
  particular recipient reads as healthy.
- **Errors:** every 404 renders `resources/views/errors/404.blade.php`. A render hook in
  `bootstrap/app.php` fires only when an **`Event`** route binding is what failed and passes the
  code that was tried, so the page can name it; everything else (a missing photo under a real
  code, the JSON uploader) falls through untouched. The six-tile join form is
  `partials/code-entry.blade.php`, shared by that page and the home page — its styles live in
  `partials/theme.blade.php`, and its submit handler refuses a code that isn't six characters
  rather than spending a page load to be told the same thing.

**Client (`resources/js/`)** — pure, unit-tested logic vs dumb browser glue:
- Pure (Vitest): `capture-flow.ts` (the whole booth as a state machine), `strip-layout.ts` (grid
  geometry), `strip-footer.ts` (the footer band — the logo box, and the caption's typesetting),
  `strip-theme.ts`, `filters.ts` (CSS strings + colour matrices), `upload-queue.ts`, `in-app.ts`,
  `pending-session.ts` (the IndexedDB store, tested for real against `fake-indexeddb`).
  `templates.ts` is pure too but has no test of its own: it is the registry the rest read, and it
  is exercised through them.
- Glue (device-tested): `camera.ts` (getUserMedia + filtered frame grab), `capture.ts` (wires the
  state machine to the DOM), `strip-compose.ts` (draws the strip; every measurement it uses comes
  from `strip-layout.ts` and `strip-footer.ts`, which is where the tests are), `wake-lock.ts`,
  `strip-preview.ts` (live preview on create/edit forms), `upload.ts`.
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

**Download-all** (`BuildEventArchive`, `App\Models\Archive`). A host asks, a queued job zips the
event's strips and originals into `strips/` and `photos/` inside one file, and emails them a
**signed, expiring** link — no login on that route, because the link is opened on whatever device
reads the mail rather than the one they signed in on. The row exists because the build is queued:
without it there is nothing to show the host while it runs, nothing to hang a lifetime on, and
nothing for the nightly sweep to find. One build at a time per event, because a host who taps twice
should not set two copies of the same hundreds of megabytes going.

Two things it deliberately borrows from elsewhere in the app. It writes **under the event's own
prefix**, so the host's delete and the retention sweep already take it — an archive that outlived
the photos it holds would make the retention window a lie, and that is a test. And it checks the
`false` that `Storage::writeStream` returns on a refused write rather than recording the path
anyway, for the same reason `PhotoController::store` does: the alternative is emailing a host a
link to nothing.

The one measured thing: entries are added with **`ZipArchive::CM_STORE`**. Deflating a JPEG spends
real CPU to save nothing, and a busy night is thousands of them — the 4000-photo seed went from
**over ten minutes to 8.5 seconds** (50 MB peak, 149 MB out) when that changed. Photos stream
through temp files one at a time rather than through `addFromString`, which holds everything it is
handed until `close()`; on that event that would have been the whole night in memory. Staging
happens inside a `try/finally`, and **a photo row that has outlived its file is skipped rather than
fatal** — the album already answers 404 for that state, and one orphan must not cost a host the
whole night (it used to throw straight past the cleanup and leave every staged original behind, on
both attempts). The counts on the row are of what actually went into the zip.
