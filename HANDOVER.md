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
hygiene** (noindex/robots, the friendly unknown-code 404, a throttled register).
**98 Pest + 56 Vitest tests green.** Every feature slice was built red/green and then put
through an adversarial review (see Conventions).

## Stack & how to run

- **Laravel 13 + Pest 5** (PHP 8.4), **vanilla TypeScript + Vite** (no UI framework, no Tailwind —
  a hand-rolled design system lives in `resources/views/partials/theme.blade.php`).
- **SQLite locally, Postgres in production.** The code is DB-agnostic (Eloquent + portable
  migrations, no raw SQL).
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

**Routing (`routes/web.php`)** splits cleanly:
- **Guest, public (no login):** `GET /e/{code}` (capture), `/e/{code}/logo`, `/e/{code}/gallery`,
  `POST /e/{code}/photos` (throttled, CSRF-exempt), `GET /e/{code}/photos/{photo}`. The event code
  is the credential.
- **Owner, auth-gated:** `/dashboard`, `/new`, `POST /events`, `GET|PATCH /events/{code}`,
  toggle-closed, and `DELETE /e/{code}/groups/{group}`. `Event::managedBy($user)` = owner OR admin,
  else 403.
- **Auth:** register/login/logout (hand-rolled `AuthController`, styled to the design system).
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
  colour matrices), `upload-queue.ts`, `in-app.ts`.
- Glue (device-tested): `camera.ts` (getUserMedia + filtered frame grab), `capture.ts` (wires the
  state machine to the DOM), `wake-lock.ts`, `strip-preview.ts` (live preview on create/edit forms),
  `upload.ts`.
- **Filters are the subtle bit:** each filter is one op-list → a CSS string (live preview + the
  Chrome `ctx.filter` fast path) AND a 4×5 colour matrix. iOS Safari ships `ctx.filter` disabled, so
  `grabFrame` feature-detects it and falls back to a `getImageData` colour-matrix pass — verified to
  match the CSS path within 1–2/255.

**Server** — `EventController` (create/manage/dashboard/logo/QR), `PhotoController` (upload +
idempotent per `(event_id, group_uuid, slot)`, serve, session delete), `AuthController`. Strip
**layout/shot-count** and **colour themes** live as data in `Event::TEMPLATES` / `Event::STRIP_THEMES`
(PHP, for the form + validation) mirrored by geometry/hex in `templates.ts` / `strip-theme.ts` (JS,
for the canvas) — **keep the keys in sync by hand** (noted in both files).

## Deployment

`DEPLOY.md` has the full Laravel Cloud checklist. The one that bit us: with no DB attached the app
falls back to the SQLite default and dies with "database.sqlite does not exist" — attach Postgres,
**redeploy** (so `config:cache` re-reads env), then `php artisan migrate --force`. Also set
`FILESYSTEM_DISK=s3` (durable photos) and `APP_DEBUG=false`. Production starts with **no data**
(the demo seed is `local`-only); register, then `php artisan photobooth:make-admin you@example.com`
to grant yourself admin.

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

### P1 — Make the pipes safe (before anything that drives more traffic)
4. Typed upload errors: branch on status in `upload.ts` — 410 → "booth just closed, save your
   strip" (keep the save affordance alive), 429 → auto-retry honouring `Retry-After`, 422 →
   terminal. Today every failure says "check your signal" with a Retry that can never succeed.
5. Longer jittered retry tail in `upload-queue.ts` (≈ 1s/3s/8s/20s) + pause while offline
   (`navigator.onLine` + the `online` event).
6. Persist the pending session (shots, strip blob, group UUID) to IndexedDB when Share is
   tapped; drain the queue on next page load. The server is already idempotent per
   (group, slot), so resume is nearly free.
7. Gallery thumbnails: generate a derivative on upload via a queued job (`QUEUE_CONNECTION`
   is configured and unused), point the grids at it, add a tap-to-enlarge lightbox with a
   per-photo save.
8. Image serving: long `Cache-Control` + ETag (or presigned redirects) on
   `PhotoController::show` / `EventController::logo` — stored files are immutable — and move
   image routes out of the session-starting middleware group.

**Gate — one combined real-device pass (Android + iPhone) after P1, before P2.** Covers the
redesign screens (HUD, looks thumbnails, tile code entry), the iOS filter fallback, countdown
pacing, and the new failure/persistence paths, all in one session.

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
13. Password reset, then email verification (mail is live on the deploy; reset matters more).
14. Download-all ZIP: queued job streams S3 → zip, emails a signed expiring link. Never
    client-side (CORS + mobile memory).
15. Event delete in the UI, reusing `photobooth:purge-event` logic (and give the command a
    `--force` flag so it can be scheduled).
16. Tri-state album privacy (hidden / PIN / open) + a stated retention window with a graceful
    expired-album page.

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
- Event codes: 6 chars from an unambiguous alphabet (no O/0/1/I), case-insensitive.
- Recent git history is the best per-slice narrative — each commit message explains the why.
