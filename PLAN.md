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

Per-shot retakes · back-camera toggle · filters/overlays/GIFs · custom strip templates UI ·
IndexedDB persistence / offline queue · PWA install · live client-side gallery ·
file-upload fallback · owner accounts/auth · thumbnails · resumable uploads · multi-language.

## Roadmap

1. ~~**Walking skeleton**~~ — done, phone-verified over the tunnel.
2. ~~**Real photobooth**~~ — done: countdown, template-driven multi-shot flow, strip composition,
   consent + sequential idempotent uploads, camera-lost recovery. Real-device pass done on
   Android + iPhone (happy paths, screen-lock recovery, denial error all confirmed).
3. ~~**Owner basics**~~ — done: event create form, printable QR owner page, gallery grouped by
   session (strips prominent), per-session delete + `photobooth:purge-event` command,
   event-closed flag (uploads 410, booth page explains, album stays), 60/min/event upload throttle.
4. **Event hardening** — camera-denied recovery screen, in-app-browser interstitial, wake lock,
   rotate-to-portrait overlay, save-via-share, **retry a fully-errored upload** (see below), device pass.

## Known behaviour & future polish

- **Partial uploads are by design, not a bug.** Uploads run sequentially, strip first, with 2
  retries each (~4s window). A transient blip recovers; a sustained outage aborts at the first
  file that exhausts retries and shows the terminal error screen. Because the strip uploads first,
  a partial session still keeps the most valuable artifact. Confirmed on-device.
- **Retry from the error screen** (Phase 4): the error screen's only action is Reload, which
  discards the in-memory blobs, so a partial session is currently permanent. The idempotency key
  (event, group, slot) already makes re-running the upload cheap and safe — already-sent slots
  dedupe to 200, only the failed ones transfer — so this is a small, high-value hardening add.
- **Gallery design pass** (later): the session layout works and doesn't overflow on mobile, but on
  narrow screens the strip and its photos are cramped side-by-side. Wants a lick of paint plus a
  responsive tweak (stack photos below the strip on narrow screens).
