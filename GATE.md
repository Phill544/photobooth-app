# The Gate — one real-device pass on Android and iPhone

Everything in P2 and beyond is queued behind this. It exists because this app is a **phone** app
that has only ever been driven from a desktop browser pane: the camera, the share sheet, the
in-app browsers, the date picker and the download behaviour are all things a desktop cannot tell
you the truth about.

Its siblings: [HANDOVER.md](HANDOVER.md) (the map + conventions),
[ARCHITECTURE.md](ARCHITECTURE.md) (why things work the way they do),
[PLAN.md](PLAN.md), [DEPLOY.md](DEPLOY.md), [OBSERVABILITY.md](OBSERVABILITY.md).

> **Add to this file whenever you ship something a phone has never rendered.** That is the whole
> reason it is a file and not a paragraph. When a check finds a bug: write the failing test first,
> then fix it — see the conventions in HANDOVER.md.

---

## Before you start

- [ ] `npm run build` — phone testing goes through a built app, not the Vite dev server.
- [ ] `php artisan migrate:fresh --seed` for the standard albums, then
      `php artisan db:seed --class=BigEventSeeder` if you want the big ones (~15s, ~280MB).
- [ ] `composer run dev` — **not** `php artisan serve` alone. The queue matters now: thumbnails,
      download-all archives and every email are queued jobs, and without a worker they sit in the
      `jobs` table doing nothing.
- [ ] Start a cloudflared tunnel at the dev server and open the printed HTTPS URL on the phone.
      **Camera APIs need a secure context** — plain `http://` will not offer the camera at all.
- [ ] Don't print a QR code against a quick-tunnel URL; they change on every restart.

**Dev login:** `demo@example.com` / `password` (seeded admin).

**The seeded event codes**, each a state that is otherwise a chore to reach by hand:

| Code | State |
|---|---|
| `PARTY2` | Empty booth, open — shoot into this one |
| `BREKKY` | One session |
| `GARDEN` | A normal closed night, 12 sessions |
| `SECRET` | Album behind a PIN — the PIN is `bridesmaids` |
| `LAPSED` | Expired, still inside its 30-day grace period |
| `SWEPT2` | Photos already deleted — empty on purpose |
| `SUNSET` / `NEWYRS` | 750 and 4000 photos (BigEventSeeder), for the album's paging |

---

## 1. Getting in

- [x] Home page code entry: six tiles over a hidden input. Both keyboards, both platforms.
- [x] The tiles fill and the caret moves as you type; `autocapitalize` gives you capitals.
- [x] A five-character code is refused **without** a page load ("Codes are six characters").
- [x] A wrong-but-valid-shaped code lands on the friendly 404 that **names the code you typed** and
      offers the form again.
- [x] Lower-case code in a typed URL still opens the booth (codes are case-insensitive).
- [x] With JS disabled the code field is a plain ruled input. **It does not submit anywhere** —
      confirmed on a phone, 2026-09-05, and confirmed in the code: the form has no `action`, and
      the only navigation to the booth is `location.href` inside the JS handler. Filed as HANDOVER
      item 24 and deliberately deferred, because the booth needs JS and a camera anyway. Re-check
      this line when that ships — the same form is on the unknown-code 404.

## 2. The booth — the happy path

- [x] Start screen: event name, the shot promise ("3 photos. One strip. Yours to keep."), and the
      tally if the album has anything in it. Event logo shows if one is set.
- [x] "Start shooting" → 3-second countdown, big numeral, shot dashes lighting up.
- [x] HUD reads `Shot 1 / 3` and the active look.
- [x] **Preview is mirrored, the captured frame is not.** Hold up something with text on it — the
      text must read correctly in the strip. This came from real event feedback.
- [x] The preview keeps the template's cell aspect, so what you frame is what lands in the strip.
- [x] Flash between shots; strip composes on-device; review screen shows it in the perforated mat.
- [x] "Share to the album" → uploading → the purple done screen.
- [x] "Take another" resets to a fresh run.
- [x] Try every template — classic (3), quad (4), grid (2×2), single — the shot count is
      template-driven, never hard-coded.

## 3. The booth — the awkward paths

- [x] **Screen lock mid-session**, then unlock: the stream dies, the app must reach `cameraLost`
      and recover by re-acquiring and restarting the current shot's countdown.
- [x] **Background the tab** mid-countdown and come back.
- [x] **Wake lock**: the screen must not sleep while the booth is open, and must be reacquired
      after a visibilitychange.
- [x] **Rotate to landscape**: the rotate-to-portrait overlay appears on touch devices, and the
      countdown does not advance behind it.
- [x] **Deny the camera**, then check the denied screen's per-platform Settings steps are actually
      correct for that OS version. Re-granting and retrying works.
- [x] **Open the booth link from inside Instagram / Messenger / TikTok.** The in-app interstitial
      should appear (UA-detected, with a getUserMedia-error safety net) and offer "open in Chrome"
      on Android / "open in Safari" on iOS.
- [x] A long event name on a short phone in landscape: the centred screens scroll rather than
      pushing content above the top edge.

## 4. Looks (filters)

- [x] "Pick a look" shows **your own face** under each filter — one still grabbed on entry, each
      tile CSS-filtered from the same op list.
- [x] Pick each of None / Noir / Golden / Cool / Pop / Film.
- [x] **iOS is the point of this section.** `ctx.filter` is a no-op in iOS Safari, so the app falls
      back to a colour-matrix pass. The strip must match what the preview promised — compare the
      same look on Android and iPhone side by side.
- [x] The filter applies to the strip **and** the originals, and is locked for the run.

## 5. Upload failures — each typed screen

The client turns a refused upload into a typed failure and the copy differs per reason. Force each:

- [x] **Closed (410)**: start a share, then close the booth from another phone/browser mid-upload.
      Because the strip uploads first, the copy must acknowledge the strip already landed.
	  NOTE: Too hard to test right now
- [ ] **Throttled (429)**: hammer uploads past 60/min for one event; `Retry-After` is honoured.
- [ ] **Rejected (422)**: terminal — no Retry button, and the Save link is still there.
NOTE: Unsure how to test
- [x] **Network**: turn the radio off mid-upload. Retry appears and re-sends only the missing slots.
- [ ] **Offline hold**: with no signal, the uploading screen shows "No signal right now — this
      carries on by itself when it's back", and it resumes on its own when the radio returns.
- [x] **Resume notice**: kill the tab mid-upload, then reopen the booth. `#resume-notice` narrates
      the leftover upload finishing in the background.
- [x] **iOS Safari private mode.** IndexedDB is unavailable there, and the booth must still shoot,
      share and save — the store is a safety net, never a dependency.
- [x] At no point may a screen that hides the Save link appear while an unsaved strip is in hand.

## 6. Saving a strip

- [x] "Save to phone" on the **review** screen (the blob is prepared on entering review, so it is
      ready inside the user gesture).
- [x] "Save my strip" on the **done** screen — native share sheet where `canShare({files})` says
      yes, long-press/download fallback where it doesn't.
- [x] The saved file has a sensible name, not a hash.

## 7. The album

- [x] Wall of strips; Strips / All photos tabs both work; the order flip is a real link.
- [x] Header counts: strips and photos are separate numbers, and "photos" never includes strips.
- [x] Tap a tile → lightbox with the full-size image → "Save this photo" saves it.
- [ ] Escape/backdrop/× all close the lightbox and it stops downloading the big file.
NOTE (2026-09-05): backdrop tap confirmed working on the phone — the one thing here that carried real
iOS risk (a delegated document click on a div with no `cursor:pointer`). The rest is not phone-shaped:
Escape needs an attached keyboard, and "stops downloading" cannot be observed on a phone at all
(100-400KB file, and the server logs a completed 200 either way). Split those two off to a desktop
check in the pending rewrite of this file.
- [x] **Paging on `NEWYRS`**: scroll and watch pages arrive on approach; then tap "Load more"
      before the observer fires and confirm it appends rather than navigating away.
- [x] Empty album (`PARTY2`, before you shoot) says "No photos yet — be the first."
- [x] Delete a session as the host (`confirm()` then gone), and the album still reads correctly.

## 8. Album privacy — three states

- [x] **PIN** (`SECRET`, PIN `bridesmaids`): type the *word* on both keyboards.
      `autocapitalize="off"` must actually hold on iOS, and **all 11 characters must go in** — a
      `maxlength` that disagreed with the validator was a real bug caught in review.
- [x] The wrong PIN shows the error and keeps the album shut.
- [ ] The unlock survives backgrounding the tab, and does **not** unlock a second PIN'd album.
NOTE: Changing the pin doesn't require guests to put in new pin. Bug? Switching to private will correctly kick people out.
- [x] **Hidden**: switch `PARTY2` to "Only me". The booth must offer **no** album link on either
      platform, and the consent line above Share must say *only the host* before you tap it.
- [x] **Open**: the consent line says *anyone with the link*.
- [x] As the host, all three states still show you the album.

## 9. Retention — the end-of-life screens

- [ ] **`LAPSED`** as a guest: the expired album page. Full-screen dark type, no controls — a
      screen shape nothing else on the phone exercises.
- [ ] **`LAPSED`** as the host: the album still opens, with the countdown banner and a link to give
      it more time.
- [ ] **`LAPSED`** booth: "This event has finished, and its photos are no longer kept."
- [ ] **`SWEPT2`** as a guest: same expired page. As the host: "photos were deleted on …", and the
      retention panel offers **no** date field.
- [ ] **The review screen's second consent line** ("Photos are kept until …"). It fits at 375×812
      in a desktop pane, but this is the screen with the least room on a real short phone.
	  NOTE: These are all tough to review on prod. Thoughts?

## 10. Host screens on a phone

The host does half of this from their own phone at a venue, not from a desk.

- [x] Dashboard rows: live dot, code, count, and status reading Live / Closed / **Finished**.
- [x] The verification nag, if the account is unverified.
- [x] Owner page: the poster panel, the QR, the stats. "Print the poster" from a phone.
- [x] **"Edit the look"** fold: the layout and colour swatch pickers are painted by JS — check they
      render, and that the live strip preview redraws as you change things.
- [x] **"Album · …"** fold: the three privacy choices are prose radios, not swatches, and the
      summary line states the current setting without opening the fold.
- [ ] **"Photos · …"** fold: the `type="date"` field. **iOS renders its own picker and this is the
      app's first date input.** Check the summary still reads as a state ("Photos · kept until
      29 Nov 2026") at 375px.
	  NOTE: This probably shouldn't be surfaced as it'll be part of the support/revenue structure, people pay and get 30/90 days etc. Good to support in the backend though.
- [x] **Download everything** panel in all three states: request, "Building your download…", and
      ready-with-a-link-plus-build-a-fresh-one.
- [x] Delete an event: the panel is folded, asks you to type the code, and a wrong code reopens the
      panel with the error **on screen** (it was measured 270px below the fold without that).
- [x] The invite/share affordances (native sheet, copy-link, raw URL) on every page that has them.

## 11. The mail journeys

Every one of these screens is reached **from a mail app**, which means a different browser from the
one the host logged in with. None of them has ever been opened that way.

- [x] **Password reset**: request it, open the link from the phone's mail app. The form must stand
      alone in the in-app browser, and the password managers should offer to save the new one.
- [x] **Verification**: same journey, and it must land on `/new` rather than the dashboard.
- [x] **Download-all link**: the archive route sends `Content-Disposition: attachment` for a file
      that can be hundreds of megabytes. What iOS Safari and Android Chrome each do with that — and
      whether it lands anywhere a host can find — is worth seeing once before a host tries it at
      the end of a night.
- [ ] An expired download link answers honestly rather than 500ing.
NOTE: Can't test this on prod yet

> **Mail is live on Resend**, with no sandbox and no recipient restriction, so any address works —
> prefer one that is *not* on `quikbooth.com`, since a shared sending pool is judged per recipient
> domain. One trap: an address that hard-bounced on an earlier attempt sits on Resend's suppression
> list, where a send is accepted and then dropped with nothing to say so — [MAIL.md](MAIL.md) has
> how to check. If you are on a dev machine with `MAIL_MAILER=log` instead, grep the link out of
> `storage/logs/laravel.log` and open it on the phone by hand — remember the tunnel host differs
> from `APP_URL`, so edit the host.

## 12. Worth a look while you are there

- [ ] The album and host pages in both light and dark system settings.
- [ ] Any screen with `prefers-reduced-motion` on (the caret pulse and strip tilt should stop).
- [ ] A slow connection (throttle to 3G): the album's lazy tiles and the upload screens.
NOTE: Leaving these for now