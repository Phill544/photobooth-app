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

- **Schema**: `events(name, code)` + `photos(event_id, kind original|strip, group_uuid, slot, path)`
  with `unique(group_uuid, slot)` so upload retries over flaky wifi are idempotent.
- **Routes**: `GET /e/{event:code}` capture page · `POST /e/{event:code}/photos` upload ·
  `GET /e/{event:code}/gallery` album · `GET /e/{event:code}/photos/{photo}` serves the image
  file (scoped to the event so photo ids can't be enumerated across events).
- **Event codes**: 6 chars from `A-Z2-9` minus lookalikes (`0 O 1 I`), uppercased on lookup.
- **Client modules** (pure, Vitest-tested): strip layout geometry, crop+mirror math,
  capture state machine, upload sequencing. Browser glue (`camera.ts`, DOM wiring) stays dumb.
- **iOS Safari constraints baked into the design**: one camera stream for the whole session,
  loose `ideal` constraints, frame grabs via `drawImage` (no ImageCapture on iOS), stream dies on
  lock/background so the state machine has a `cameraLost → reacquiring` transition,
  `<video playsinline autoplay muted>`, front-camera preview AND capture both mirrored.
- **Camera requires HTTPS**: phone testing goes through a cloudflared tunnel against a
  built app (`npm run build`) — see README quickstart.

## Deliberately deferred (v1 non-goals)

Per-shot retakes · back-camera toggle · filters/overlays/GIFs · custom strip templates UI ·
IndexedDB persistence / offline queue · PWA install · live client-side gallery ·
file-upload fallback · owner accounts/auth · thumbnails · resumable uploads · multi-language.

## Roadmap

1. ~~**Walking skeleton**~~ — done, phone-verified over the tunnel.
2. ~~**Real photobooth**~~ — done: countdown, template-driven multi-shot flow, strip composition,
   consent + sequential idempotent uploads, camera-lost recovery. (Real-device pass pending.)
3. **Owner basics** — event create form, printable QR page, gallery grouped by session, delete session/event, event-closed flag, rate limiting.
4. **Event hardening** — camera-denied recovery screen, in-app-browser interstitial, wake lock, rotate overlay, save-via-share, device pass.
