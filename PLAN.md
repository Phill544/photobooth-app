# Photobooth — Plan & Decisions

A photobooth web app for events. Guests scan a QR code, take a set of photos,
see them composed into a photo strip on their phone, and share them to the
event's album. Event owners view the album on the website.

## Locked decisions

| Decision | Choice | Why |
|---|---|---|
| Client platform | Mobile web app (no install) | Android Instant Apps were shut down Dec 2025; iOS App Clips need a native parent app. Web is the only zero-install path. |
| Repo shape | One repo — Laravel serves the client (Blade + Vite + vanilla TS) | No CORS, no tokens, one deploy. A SPA framework buys nothing for ~5 screens. |
| Strip rendering | On-device via `<canvas>` | Instant preview; server stays a dumb file sink (no image processing). |
| Shot count | **Defined by the strip template — never hard-coded** | Future templates may hold more or fewer photos. |
| Upload payload | All originals + the strip, strip first, one file per request | Owner wants raw images server-side; strip is the most valuable artifact. |
| Gallery visibility | Anyone with the event code | Simplest MVP; browsing the album is a feature. Consent text says "visible to everyone with the event link". |
| Search indexing | Guest pages `noindex`; only `/` is meant to be crawlable | An album is only as private as its code, so one crawled link would publish a whole event. A meta tag can't reach an image, so served photos/logos carry `X-Robots-Tag: noindex` too. |
| Auth | None in MVP — the event code is the credential | Upgrade path: starter kit + `owner_id` + middleware on owner routes. Guest flow never changes. |
| Photo serving | Private disk behind a controller route (`Storage::response`) | Same effort as public disk now; future auth becomes a one-middleware change. |
| Database | SQLite (WAL) | Zero setup; swap to MySQL is a `.env` change if a big event needs it. |
| Testing | Pest (server, red/green TDD) + Vitest (pure client modules) | Camera glue is device-tested manually, everything else is unit-tested. |

## Architecture notes

- **Schema**: `events(name, code, closed_at)` + `photos(event_id, kind original|strip, group_uuid,
  slot, path)` with `unique(event_id, group_uuid, slot)` so upload retries over flaky wifi are
  idempotent (scoped to the event — group_uuids are visible in each gallery).
- **Guest routes**: `GET /e/{event:code}` capture page · `POST /e/{event:code}/photos` upload
  (throttled 60/min per event code) · `GET /e/{event:code}/gallery` album ·
  `GET /e/{event:code}/photos/{photo}` serves the image file (scoped to the event so photo ids
  can't be enumerated across events) · `DELETE /e/{event:code}/groups/{group}` deletes a session.
- **Owner routes**: `GET /new` create form · `POST /events` create · `GET /events/{event:code}`
  owner page (printable QR, links, photo count, close toggle) ·
  `POST /events/{event:code}/toggle-closed`.
- **Purge**: `php artisan photobooth:purge-event {code}` deletes an event, its rows, and its files.
- **Event codes**: 6 chars from `A-Z2-9` minus lookalikes (`0 O 1 I`), uppercased on lookup.
- **Client modules** (pure, Vitest-tested): strip layout geometry, crop+mirror math,
  capture state machine, upload sequencing. Browser glue (`camera.ts`, DOM wiring) stays dumb.
- **iOS Safari constraints baked into the design**: one camera stream for the whole session,
  loose `ideal` constraints, frame grabs via `drawImage` (no ImageCapture on iOS), stream dies on
  lock/background so the state machine has an explicit `cameraLost` state (recovery re-acquires
  the stream and restarts the current shot's countdown) and every path back into a countdown
  re-checks camera liveness first, `<video playsinline autoplay muted>`, front-camera preview
  AND capture both mirrored.
- **Guest upload route is CSRF-exempt**: booth pages sit open for hours, sessions expire, and a
  419 would lose the guest's strip. The endpoint has no authenticated session to protect — the
  event code is the credential.
- **Camera requires HTTPS**: phone testing goes through a cloudflared tunnel against a
  built app (`npm run build`) — see README quickstart.

## Deliberately deferred (v1 non-goals)

Back-camera toggle · GIF/boomerang · PWA install · live client-side gallery · file-upload
fallback · resumable uploads · multi-language · password reset + email verification (deferred
with owner accounts).

Two of these came back in P1 and shipped: **IndexedDB persistence / offline queue** (an
interrupted share now finishes itself) and **thumbnails** (a queued derivative per photo). And
**per-shot retakes** left this list for a different reason — it is now an explicit product
decision not to build it (2026-08-26; see HANDOVER.md "Explicitly not doing").

## Owner accounts (done)

Hand-rolled auth (register/login/logout, no Tailwind scaffolding) styled to the design system.
`events.owner_id` (nullable FK) + `users.is_admin`. Create/manage routes are behind `auth`;
`Event::managedBy($user)` gates show/update/toggle-closed/session-delete (owner OR admin, else 403).
`/dashboard` lists an owner's events; admins see every event. The **guest flow stays fully public**
(join by code, capture, gallery, upload, photo/logo serve). Deferred: password reset, email
verification, an admin UI to grant `is_admin` (set via seeder/tinker for now), and impersonation
(admin oversight is read/manage-all; add "log in as" only if needed). Dev login: demo@example.com /
password (seeded admin). **Next: deploy to Laravel Cloud** (managed Postgres + S3 for photos/logos).

## Roadmap

1. ~~**Walking skeleton**~~ — done, phone-verified over the tunnel.
2. ~~**Real photobooth**~~ — done: countdown, template-driven multi-shot flow, strip composition,
   consent + sequential idempotent uploads, camera-lost recovery. Real-device pass done on
   Android + iPhone (happy paths, screen-lock recovery, denial error all confirmed).
3. ~~**Owner basics**~~ — done: event create form, printable QR owner page, gallery grouped by
   session (strips prominent), per-session delete + `photobooth:purge-event` command,
   event-closed flag (uploads 410, booth page explains, album stays), 60/min/event upload throttle.
4. ~~**Event hardening + UX pass**~~ — done (real-device pass still yours to run): camera-denied
   recovery screen with per-platform Settings steps, in-app-browser interstitial (UA-detected +
   getUserMedia-error safety net), screen wake lock (reacquired on visibilitychange),
   rotate-to-portrait overlay on touch devices, save/share-my-strip via the Web Share API with a
   long-press + download fallback, retry-a-failed-upload, an invite/share affordance on every main
   page, gallery↔booth navigation, and a full visual design pass (shared theme, redesigned gallery).

## Richer booth (in progress)

Growing the guest experience. Sequence: templates → branding → filters → GIF.

1. ~~**Strip templates**~~ — done. The layout engine is a grid model (`columns` +
   `cellCount`); templates live in `resources/js/templates.ts` (geometry) with matching
   keys+labels in `Event::TEMPLATES` (form + validation). Owner picks one at `/new`, it's
   stored on the event, and the capture flow reads it via `data-template`. Ships classic
   (3×1), quad (4×1), grid (2×2), single. Shot count stays template-driven.
2. ~~**Per-event branding**~~ — done: owner picks a strip colour theme (Midnight/Blush/Forest/
   Sand/Champagne) and an optional caption (defaults to event name) at `/new`, and can **edit name,
   layout, colour, and caption after creation** from the owner page (`PATCH /events/{code}`) with the
   same live strip preview (`strip-preview.ts`, shared by both forms via `[data-strip-form]`).
   Colours live in `strip-theme.ts` with keys mirrored in `Event::STRIP_THEMES`; passed to the strip
   via `data-theme`/`data-caption`. Owners can also **upload a logo** (create or edit) which is
   stored on the private disk, served at `GET /e/{code}/logo`, and drawn in the strip footer
   **instead of the caption** (one or the other); a remove option clears it. The live preview shows
   it too. Branding is now complete.
3. ~~**Filters**~~ — done: an opt-in "Add a filter" path (quick shoot stays filter-free) with a
   live-preview chip picker (None/Noir/Golden/Cool/Pop/Film). `filters.ts` defines each look once as
   an op list → CSS string (preview + Chrome ctx.filter fast path) AND a 4×5 colour matrix (the iOS
   fallback, since ctx.filter is a no-op on iOS Safari through 2026). Both paths verified to match
   within 1–2/255. Filter applies to strip + originals; chosen once, locked for the run.
4. **GIF / boomerang** — short burst capture + animated output (biggest lift; needs research).

## Redesign (done)

Every screen rebuilt to the Claude Design canvas `Redesign.dc.html` (project
`a8f5bd22-8f0d-4bbb-a570-798f0a5e0f61`) — see **Design system** above for the direction, the
components, and the three places the implementation deliberately departs from the canvas. What
changed behind the paint:

- **Join** is six code tiles over a hidden input (progressive: a plain ruled field without JS).
- **Booth** start screen leads with the event name and one blue CTA; the camera screen gets a HUD
  (`Shot 1 / 3`, the active look) and shot dashes; the looks picker shows the guest's own face
  under each filter (one still grabbed on entry, each tile CSS-filtered from the same op list).
- **Review** gained "Save to phone" — the strip File/blob is now prepared on entering *review*
  rather than *done*, so both screens' save affordances have it ready inside a user gesture. Both
  are plain `<a download>` links upgraded to the share sheet when `canShare({files})` says yes.
- **Album** is a wall of strips with working Strips / All photos / order controls.
- **Host**: dashboard rows show a live dot + code + count + status; `/new` and the owner page are
  split ivory/ink with by-eye pickers and a live strip; the owner page's left panel *is* the
  printable poster (`@media print` drops everything else and inverts it to ink on paper).
- Controllers now pass the counts the design puts on screen (booth tally, album and event stats).

**"Photos" always means the shots a guest took.** The composed strip is a `photos` row too
(`kind = 'strip'`), so every count the UI labels "photos" filters `kind = 'original'` and the strips
carry their own stat beside it — otherwise the album header disagreed with the All-photos tab it
labels. Enforced in all four places (`dashboard`, `capture`, `show`, `gallery`) and covered by tests
on the booth, album, and event pages.

Also from event feedback: front-camera frames are now captured **un-mirrored** (preview stays
mirrored) so text/signs in the strip read correctly. Countdown is **5 seconds**. The create form
and the owner edit form both show a **live strip preview** (`strip-preview.ts`, bound via
`[data-strip-form]`) that redraws via the real compose modules (placeholder cells) as the owner
changes layout/colour/caption/logo.

## Design system

Direction: **a booth that looks like a night out.** Near-black rooms, one electric blue, ivory
type, film perforations down every edge, and the strip as the hero on every screen. Every screen
has exactly one obvious thing to do. (Imported from the Claude Design canvas `Redesign.dc.html`.)

- One shared theme in `resources/views/partials/theme.blade.php` (type + tokens + components),
  `@include`d in every `<head>` (NOT via Vite — the closed-event capture branch loads no JS entry).
  `<body class="ctx-dark">` = the booth (home/auth/capture); `ctx-light` = paper (album/host).
  A context can also be **nested** as an island (the dark preview rail on `/new`, the poster panel
  on the owner page) — `.ctx-dark, .ctx-light { color: var(--text) }` exists so a nested island
  re-reads its own text colour instead of inheriting the parent's already-computed one.
- **Type**: Instrument Serif (display), Instrument Sans (body), DM Mono (codes, counts, labels).
- **Palette**: ink `#0B0B10` / ivory `#F4F2ED`, accent blue `#3A86FF` (the one CTA colour, with a
  glow shadow), purple `#8338EC` (the celebration screen), pink `#FF006E` (live / in-progress),
  yellow `#FFBE0B` (the looks picker). Strip **theme** colours are unrelated and stay in
  `strip-theme.ts` — they are the owner's choice, not the app's chrome.
- **Perforations**: `.perf-edge` runs sprockets down a page or panel edge; `.strip-mat` is the
  perforated sleeve a composed strip sits in. Both read `--perf-*`/`--mat-*` from the current
  context, so a strip of any owner-chosen colour sits in a mat that matches the *page*.
- Fields are a ruled line, not a box. Layout and strip colour are picked **by eye**: radio groups
  whose swatches are painted by `strip-preview.ts` from `TEMPLATES` / `STRIP_THEMES`, so the
  pickers and the canvas can't disagree about a shape or a hue (labels stay in the DOM, sr-only,
  for a11y and validation copy).
- `<x-stat>` renders a big serif figure over a mono caption, plus one sr-only phrase — screen
  readers get "28 strips", not two unrelated fragments.
- Invite affordance (`.share` + `.share-btn`/`.share-copy`/`.link-chip`) driven by
  `partials/share-script.blade.php`: native share sheet where available, copy-link everywhere else,
  raw URL always visible. Strip file-share lives in `capture.ts` (needs the built File up-front).

### Deliberate departures from the canvas

- The canvas shows the booth's start screen over a full-bleed **event photo**. There is no
  cover-image feature, so it's a blue/purple glow over ink instead (plus the event logo if one is
  set). Adding cover photos would be a feature, not a restyle.
- The canvas shows a **full-bleed** camera preview. The preview keeps the template's cell aspect
  (`--cell-aspect`) instead, because `grabFrame` crops the stream to that aspect — a cover-cropped
  preview would stop being WYSIWYG. The HUD chips and shot dashes live in the surrounding black.
- The canvas's album tabs (Strips / All photos / order) are **real** controls, not decoration —
  but they are not all the same kind. Strips / All photos toggle two panels that are both already
  on the page; **order is a link the server answers** (`?order=oldest`), because the album arrives
  a page of sessions at a time and a client-side flip could only reorder the page it can see.
  Per-session grouping of originals is implicit in that ordering rather than rendered as stacked
  session cards, and the ordering moves whole **sessions**, never the shots inside one — a strip's
  frames always read in capture order (the server sorts sessions by `MAX(id)` and their photos by
  `slot`).
- The swatch pickers need JS to paint their shapes and hues; without it they are blank boxes and
  circles (the labels are still in the DOM, sr-only). Accepted, because the live strip preview that
  those pages exist for needs JS anyway — but it *is* a regression from the old `<select>`s.
- `--text-faint` on paper sits only one notch off `--text-muted`: the canvas's own faint greys are
  ~3.1:1 on ivory, and everything using the token is real text (the Delete control, the mono
  labels, hints, placeholders). Every token pair actually used together is ≥4.5:1, measured.

## Known behaviour

- **Partial uploads degrade gracefully.** Uploads run sequentially, strip first, with 2 retries
  each (~4s window). A transient blip recovers; a sustained outage now parks on an **upload-failed
  screen with a Retry button** that re-runs the upload — already-sent slots dedup on the server, so
  only the missing files transfer. Because the strip uploads first, even a never-retried partial
  keeps the most valuable artifact.
