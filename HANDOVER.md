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
Design canvas `Redesign.dc.html`; see PLAN.md "Design system" + "Redesign"). **81 Pest + 56 Vitest
tests green.** Every feature slice was built red/green and then put through an adversarial review
(see Conventions).

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
- Verify in the browser (the in-app Browser pane) by reading actual output, not by asserting it
  "should" work — e.g. read a composed strip's pixels, don't assume.

## What's next (open threads)

Nothing is broken or half-done. Candidate next work, roughly in the order discussed:
- **Real multi-device event pass** of filters + the 5-second countdown feel (only real phones
  confirm the iOS filter fallback and countdown pacing).
- **GIF / boomerang** capture — the last big "richer booth" idea; needs a research pass on encoding
  + animated upload before building.
- **Admin impersonation** ("log in as an owner") — deliberately deferred; small add on top of the
  existing admin oversight (needs an audit log + a visible banner).
- **Password reset + email verification** — deferred with owner accounts; pair with mail config now
  that it's deployed.
- **Gallery thumbnails** for big albums — the album is now a grid of full-size strips, so this
  matters more than it did.
- **Event cover photo** — the redesign's booth start screen wants one (see PLAN.md "Deliberate
  departures"); a gradient stands in for now.
- **A real-device pass on the redesign** — the booth's HUD, the looks thumbnails, and the tile
  code entry have only been checked in the desktop browser pane.
- Deferred v1 non-goals (see PLAN.md): per-shot retakes, back-camera toggle, PWA, IndexedDB
  session persistence, resumable uploads, multi-language.

## Handy facts
- Dev login (local only): `demo@example.com` / `password` (seeded admin).
- Event codes: 6 chars from an unambiguous alphabet (no O/0/1/I), case-insensitive.
- Recent git history is the best per-slice narrative — each commit message explains the why.
