# Handover — Quikbooth

Orientation for the next agent picking this up. **This file is the short version**: what the app
is, how to run it, the conventions you must follow, and what is left to build. The detail lives in
its siblings.

| Doc | What it holds | Read it when |
|---|---|---|
| **[ARCHITECTURE.md](ARCHITECTURE.md)** | How the app is put together and **why** — routing, the paged album, privacy and retention, the durability and mail guards, download-all, the client modules | Before changing anything server-side |
| **[GATE.md](GATE.md)** | The real-device checklist (Android + iPhone) run before the doors open | Before the gate pass, and whenever you ship something a phone has never rendered |
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
host a signed, expiring link → **an owner page that answers what it was asked**: every control
redirects back to its own fold with a line saying what happened, a fragment from anywhere opens the
fold it names, and the confirmation a verification writes is finally rendered rather than dropped.
→ **a strip a host can design**: cells at 960×720 (the size the camera was already handing over),
and one uploaded image behind the whole strip, cover-cropped, with each photo pulled in to 92% of
its cell so the artwork reads as a mat rather than the 24px hairline the geometry left it, plus a
"No caption" tick for the host whose artwork already says their name. Both forms state the strip's
exact pixel size as the layout changes, and hand over a generated layout-guide PNG with the photo
windows marked, so a host can design against real bounds.
→ **a save that saves**: every Save in the booth — review, upload-failed and done — writes the file
and nothing else, and the share sheet is one button of its own ("Share it", on the done screen).
One control cannot mean both: `navigator.share` takes no target hint, so Save-as-sheet landed
wherever the OS put first. Each one says "Saved!" for a moment afterwards, because a download on
Android is completely silent and the sheet used to be the only feedback. Copy link left the done
screen with all this; "Invite others" and the raw URL chip are what hand the event on there.
**450 Pest + 137 Vitest tests green.** Every feature slice was built red/green and then put
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
- **Over a tunnel, build the assets and delete `public/hot`.** Vite writes that file when
  `composer run dev` starts, and while it exists `@vite` points every script at
  `http://[::1]:5173` — plain HTTP, from an HTTPS page. The browser upgrades the module script,
  Vite has no TLS, and it fails with `ERR_SSL_PROTOCOL_ERROR`. `capture.ts` never loads, so the
  booth's own controls are dead while the rest of the page (plain links, the inline share script)
  carries on working — which reads exactly like a code bug and is not one. Deleting `public/hot`
  makes the app serve `/build/...` same-origin, and `trustProxies(at: '*')` gets the scheme and
  host right. Cost: `npm run build` after every JS change while the file is gone.

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

## What's next — the road to a public release

Everything below is ordered for one goal, set on 2026-09-05: **a public release** — strangers
register, run an event, and soon after pay. The list was rebuilt that day from an eleven-agent pass
that read every open item against the code (seven investigators, three independent sequencings,
one critic); the placements are the rulings and the "why" lines are the facts they rest on. The
numbered phases before it came from the 2026-08-26 competitive review ("State of the Booth" —
https://claude.ai/code/artifact/63d587ad-5a7a-4bd5-9b8e-6a89a1dacca0, ask Phill for access).

Two things are kept apart on purpose. **Decisions only Phill can make** are not code and come
first; all six were answered on 2026-09-05, and what is left of them is below. And the work has
**two stages**: everything a stranger needs on the night (Stage 1), then Stripe (Stage 2). They
were once meant to be two releases — doors open free, money a fortnight later — but D1 puts the
pricing numbers in the Stripe sitting, and a page with no number on it cannot honour "limits
stated up front", so **the doors open only once Stage 2 has landed too**. The stages stay as the
order to build in. Stripe's ops surface (AU business verification, ABN/GST stance, a payout
account) has wall-clock latency no commit shortens, so start it the day Stage 1's blockers are
done rather than the day they are reviewed.

How to work it: top to bottom by section; **items within a section are independent** unless a
line says otherwise. Each item is a slice: red/green TDD, then an adversarial review (see
Conventions). **Delete an item when it ships** — this list is only what is left; the Status line
and the git log record what shipped. Item numbers are stable IDs (9–25 from the review, 26+ from
this pass), never reused or renumbered; a section whose items are all gone disappears with them.
One known defect is on the list (25), deferred with the reasoning written down.

### Decided — 2026-09-05, and what each one leaves open

Phill answered the six standing decisions. The answers a slice will go looking for are in PLAN.md's
"Locked decisions" table; what follows is what each one settles here, and what it deliberately
does not.

- **D1 — One purchase per event, through Stripe Checkout.** Not a subscription, nothing metered
  mid-event, the limit is one sentence. It is the one shape that passes the market-anger checklist
  and the schema — the per-event window column already exists, and nothing else is limited
  anywhere (`is_admin` only grants see-all/manage-all). **The numbers are decided in the sitting
  that builds 41**, with the accountant, so the Stripe Price object is created once: the free and
  paid windows in days, the AUD price and the GST stance, whether a purchase is allowed after the
  night (recommend yes, any time before `photos_purged_at` — the grace period is the natural
  upsell and the album already links there), whether anything besides the window differs between
  free and paid (recommend nothing — every extra difference is another sentence to state and
  another test surface), and refunds (by hand from the Stripe dashboard, window kept, stated in
  the terms). Until that sitting **no page states a free window**: 30 keeps its "what a host gets"
  copy and drops the limits line, 26's terms carry no refund figure, 23 leaves `RETENTION_DAYS =
  90` where it is, and each event goes on showing its own keep-until date exactly as it does today.
  **What the window counts from is settled (2026-09-06): the first photo.** It was the one part of
  D1 that could not be revised afterwards — the window is a promise made to guests at the moment of
  consent, and once a stranger has run an event it has been made — so it was decided ahead of the
  sitting rather than in it. `Event::startRetentionWindow()` now starts the clock from
  `PhotoController::store`; only the *number* of days waits for 41.
- **D2 — The guest's own phone.** That is the model, so item 12's device token can group a
  returning guest's strips. **Kiosk mode, if it ever comes, is a future functionality change** —
  not a variant to keep the code ready for, so don't preserve behaviour that only makes sense
  passed around a room. The `#again` comment in `capture.ts` ("drop the last guest's look") is the
  one place that assumed a kiosk; that handler now dispatches `reset` and the comment went with it. "Take
  another" still returns to the start screen, which is right under either model.
- **D3 — The wordmark links to `/`, on every page**, with one context button beside it ("Your
  events" / "Back to the booth" / "Manage this event"); the booth's fixed kiosk screens keep
  in-screen exits and never grow a bar. Shipped, and written into PLAN.md's design system.
  Phill's note: **there is room for improvement here.** Take it as the starting point rather than the finished navigation and revisit it after
  launch — it is an href and a button, not a structural choice, and nothing downstream is built on
  its being final.
- **D4 — Split: the words now, the artwork with Stripe.** A **support inbox** and the **privacy
  and terms text** are needed before the doors open — item 26 is blocked on both, guests' faces
  are personal information under the AU Privacy Act regardless of payment, and Stripe's business
  settings will want the same inbox. Write the policy from ARCHITECTURE's real behaviour, never
  aspiration. **The regions were read on 2026-09-06 and are settled**: the app and Postgres are
  `ap-southeast-2` (Sydney) and Nightwatch is Sydney, but **photos are on Cloudflare R2 with
  jurisdiction `default`**, which guarantees residency only for `eu`/`us`/`fedramp` — so the
  photographs have no country guarantee and the jurisdiction cannot be changed after bucket
  creation. Resend is **us-east-1** and sends through Amazon SES there. Disclose Resend and
  Nightwatch as overseas providers. **Not Google** — D4 missed it and the audit found it, but item
  51 shipped on 2026-09-06 and the fonts are ours now, so the disclosure is gone rather than
  written. Nightwatch's *storage* region is Sydney; it is a third party, not necessarily a border
  crossing, and the clause should say which. The **app icon** (the strip-in-a-mat or sprocket
  motif, ink ground, blue accent) waits for the Stripe sitting — item 36 cannot start without it,
  so it stays parked rather than half-built against a placeholder.
- **D5 — Both, before launch.** Two settings on the Laravel Cloud dashboard, Phill's hands only.
  **`SESSION_LIFETIME=720`**: the session cookie expires after 120 minutes by default, so a host
  whose owner page sat open through a three-hour event taps "Close the booth" into a 419 — 720
  minutes covers an event. **The reasoning that used to sit here was wrong** and the 2026-09-06
  audit corrected it (item 26(c)): production is **not** on the cookie driver — nothing sets
  `SESSION_DRIVER`, so it falls back to the config default `database`, and there *is* a session
  store that a longer life grows. It is a table of guest IP addresses and user agents, so six times
  the lifetime is six times their retention. **Set `SESSION_DRIVER=cookie` in the same sitting**,
  which is what every doc already believed and what suits serverless; then 720 costs nothing.
  Both are now **recorded in DEPLOY.md's env block, flagged as not yet set** (2026-09-09) — so the
  block is the checklist, and what is missing is visible next to what is live. And the
  **spend notification** turned on, the only cost alarm until a cap is ever needed.
- **D6 — Confirmed as work rather than a question: it is item 49**, in the blockers below.

**Still Phill's own hands, and no commit can start them.** Updated 2026-09-06, after the audit
answered everything a read could answer:

- **Stand up the support inbox** (D4, blocking 26). Use **Cloudflare Email Routing** — DNS for
  `quikbooth.com` is already on Cloudflare (`cass`/`finley.ns.cloudflare.com`) and the root domain
  has **no MX**, so `hello@` and `support@` can forward to a real mailbox for free without touching
  Resend's `send.` subdomain. **Not Resend**: its inbound product delivers to a webhook rather than
  a mailbox, and it warns against putting inbound on a root domain. Email Routing is receive-only,
  so also set up Gmail's "Send mail as" over Resend SMTP — an inbox the policy names as the contact
  point has to be one you can reply *from*.
- **Two Laravel Cloud dashboard settings** (D5): `SESSION_LIFETIME=720` **and
  `SESSION_DRIVER=cookie`**, which is the one that stops a `sessions` row being written for every
  guest who opens a booth page. Plus the **spend notification**.
- **Three dashboard reads for 49 and 26**: the Postgres **point-in-time-recovery window** and
  snapshot retention (every cluster-level CLI read is broken in v0.5.2 — `database:list`,
  `database-cluster:list` and `database-snapshot:list` all send a disallowed `include` and 400,
  even when addressed by name, so this is a canvas read).
- **One support ticket to Laravel Cloud**, two questions: is **R2 object versioning** on for
  `photobooth_app_production` (the CLI exposes only name and visibility, and the bucket sits under
  a Cloudflare account that is not ours, so there is nothing to read); and what is the
  **randomly-named 40-character cookie** every response sets? It is stable per visitor and does not
  accumulate (tested with a persistent jar), and it carries Laravel's own encrypted-payload format
  rather than Cloudflare's, so it comes from something in Cloud's stack. Neither blocks drafting —
  the policy can describe a platform operational cookie honestly — but both belong in the answer.
- **Then, in the Stripe sitting**: the app icon, the AUD price with an accountant, and a Stripe
  account verified for an AU business.

### Stage 1 — everything a stranger needs on the night

**Blockers — a stranger should not be able to register until these are in.**

26. **The legal floor.** `/privacy` and `/terms` (the refund wording comes with 41 — no figure
    before then), a footer partial (Privacy · Terms · Contact) on home, the auth pages and the
    album, and a "By creating an account you agree to…" line on `/register`. None exist, and the
    review screen's consent note links to nothing longer. The reason is not Stripe: guests' faces
    are personal information under the AU Privacy Act regardless of payment, and a public register
    page with no terms is indefensible. Both pages must be crawlable — only `/` is today, and
    `robots.txt` is an allowlist by omission, so also keep every *new* endpoint (the webhook,
    client errors) out of it and grow `SearchIndexingTest`. Until 31 ships, the policy points
    account deletion at the support inbox (D4). Keep `HomePageTest`, `EventCreationTest` (`/`
    contains `/dashboard`) and `SearchIndexingTest` green.
    **The facts to write it from were established by an eighteen-agent audit on 2026-09-06** (eight
    dimensions, each adversarially verified, plus a completeness critic against the Australian
    Privacy Principles). What it turned up that no doc had recorded, all verified against the code
    or the live account:
    **(a) — resolved, and the policy must NOT name Google.** Every page used to load Google Fonts,
    making Google an undisclosed overseas recipient of every guest's IP address. Item 51 shipped on
    2026-09-06: the three families are served from `public/fonts` and `SelfHostedFontsTest` fails if
    a response so much as mentions either Google host. The disclosure is gone rather than written.
    **(b)** Photos live on **Cloudflare R2 with jurisdiction `default`** — the app and Postgres are
    `ap-southeast-2`, but R2 guarantees residency only for `eu`/`us`/`fedramp`, so the photographs
    have **no country guarantee**, and the jurisdiction cannot be changed after bucket creation.
    **(c)** Production has **only four environment variables set** (`APP_KEY`, `MAIL_MAILER`,
    `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`), so `SESSION_DRIVER` falls back to the config default
    **`database`** — not `cookie`, which DEPLOY, ARCHITECTURE and this file all claim. Confirmed
    against the live site: `quikbooth-session` returns a short id, not a payload. On that driver
    Laravel writes an `ip_address` and `user_agent` row **for every guest who opens a booth page**.
    `SESSION_LIFETIME` is unset too, so D5's 720 has not been done.
    **(d)** The live site sets **four cookies, two of them nobody has accounted for**: Cloudflare's
    `__cf_bm` on `Domain=laravel.cloud`, and one with a **randomly generated 40-character name that
    changes on every response**. The app sets no cookies of its own (`grep` for `Cookie::` in
    `app/` is empty), so both are platform-injected — ask Laravel Cloud what the second is before
    the cookie table claims to be complete.
    **(e)** No **DPA or sub-processor agreement** is evidenced anywhere for Laravel Cloud, Resend,
    Nightwatch, which is the APP 8.1 "reasonable steps" the policy will have to describe. (Google is
    no longer on that list — see (a).)
    **(f) — what Nightwatch is told, after item 52.** It is live and in Sydney, and as of 2026-09-06
    it receives a **user id and nothing else**: the default resolver's name and email are replaced by
    `Nightwatch::user(fn () => [])`, and the reset link no longer carries `?email=`, so the vendor
    never holds both halves of a reset credential. `NIGHTWATCH_CAPTURE_REQUEST_PAYLOAD` is off, so no
    request bodies. What it still receives, and what the policy must therefore disclose, is the
    **client IP address, the full URL and technical request data** for every request — sampling is
    1.0, so this is everything, not a sample.
    **Phill answered the drafting questions on 2026-09-06, so these are settled and the document can
    be written against them:**
    **Governing law** is **Victoria, Australia** — non-exclusive jurisdiction of its courts, with the
    standard clause that nothing excludes rights under the Australian Consumer Law that cannot be
    excluded. **Response commitment** is the conventional **30 days**. **The contracting entity is a
    blank**: draft both documents with `[ENTITY]` and `[ABN]` placeholders and fill them before
    publishing — an ABN does not exist yet and one is coming with Stripe anyway, so the alternative
    was holding the whole draft for it. **Nothing may publish while those placeholders stand.**
    **No guest-facing takedown control** is being built, and the policy must not imply one. The host
    already deletes a session from the album and an admin can delete any of them, so the mechanism
    exists — it is just operated by a person. A guest-facing button would be *unsafe* before item 12's
    device token: photo rows carry no guest identifier, so the only thing that could gate it is the
    album link, which every guest at the event holds — it would let anyone delete anyone's photos.
    The policy therefore describes a **manual** path (ask the host, or write to the inbox and an
    admin actions it) and says plainly that identifying which session belongs to a requester takes a
    visual check. **The album's footer is the mechanism** — it is what gives a guest who cannot reach
    the host somewhere to write, so the footer on the album is not optional in this item.
    The **only admin on production is `philljm544@gmail.com`**, which is the internal-access answer
    the policy owes; keep it true or update the clause.

50. **The app cannot tell you it was breached.** **From the 2026-09-06 audit; Phill to rule on
    whether it blocks.** `grep -rn "Log::\|logger(" app/` returns **nothing** — the application
    writes no log lines of its own, records no login timestamps, no `last_login`, no admin access
    and no read of any photograph. OBSERVABILITY.md:32 puts production on a **one-day** platform
    log tier, in its own words: "An error from Saturday night can be gone by Monday." Under the
    Notifiable Data Breaches scheme a breach must be assessed within 30 days and the affected
    individuals identified — neither is possible from one day of logs and no audit trail, and
    guests could not be notified even if they were identified because the app deliberately holds no
    contact detail for any of them. This is not a policy paragraph; it is either a slice (an
    application audit log for the handful of events that matter — sign-in, admin access to another
    host's event, session delete, purge, retention change) or a written, deliberate acceptance in
    the policy that notification runs through hosts and a public statement. **Cheap half first:**
    a `last_login_at` column and a log line on the five events above.

23. **Retention as a role split, not a deletion.** The "Photos · …" fold hands every host a
    free-form `type="date"` validated `after_or_equal:today`; the intent is that the window comes
    with what they pay for. Shape: **admins keep the free-form date** (the "someone emailed asking
    nicely" path, and the only route into GATE §9's expired/grace states on production without a
    shell — gate `retention()` so a non-admin posting a date gets 403, deliberately inverting the
    two `RetentionWindowTest` cases that let a host buy time and keep photos for good); **hosts
    get one server-computed action** — "Give it another 90 days", a POST that sets `max(now,
    current) + N` and is refused once `photosWerePurged()`. In Stage 2 that button becomes the
    paid one (41), and the day counts wait for 41's numbers — leave `RETENTION_DAYS = 90` until
    then. Two
    **Both of the things this used to wait on are done** (2026-09-09, with the owner-page slice):
    the expired album's "give it more time" link is pinned present for a host in
    `OwnerPageStatusTest`, and `#retention` renders open — by flash after a POST, and by the owner
    page's inline script for that link, which arrives as a plain GET from the album. Rewrite
    ARCHITECTURE's retention paragraph: "kept for good" becomes admin-only. Absorbs the
    retention-fold GATE §10 note. The retention POST already returns a line naming the new date, so
    the server-computed button inherits its confirmation — but note it is **not refused on a purged
    album** today: the form is not rendered there, so only a hand-made POST reaches it, and it
    redirects with a status that has nowhere to render. Refusing it is this item's job.

30. **Limits and the product, stated up front.** The home page is a join screen that says nothing
    about what Quikbooth is or what a host gets, and no page states the free window. **Now:** grow
    the `.host` block *below* the code entry (what it is; what a host gets — QR poster, album,
    strips on guests' phones, originals downloadable; "Create an account"). **With 41, not
    before:** a stated-limits line on `/new` and the home page — it needs D1's *days*, which that
    sitting settles, and a number written before then is a promise made twice. The review screen's
    consent line is **done** (2026-09-06): the anchor was settled ahead of the sitting because it
    could not be revised afterwards, and the line reads the window it starts. Copy in the existing
    layout — the marketing artboards (43) wait for a price to show.

49. **Backup and restore, confirmed and rehearsed.** **Phill's, 2026-09-05 — was D6.** No doc
    mentions either, and a retention promise on top of an unverified restore path is a promise
    nobody has checked can be kept: from the moment a stranger runs an event, this app holds the
    only copy of their night. Confirm Serverless Postgres point-in-time recovery and
    object-storage durability/versioning on the Cloud dashboard, **write the restore procedure
    into DEPLOY.md**, and rehearse it once end to end — restore into a scratch environment and
    read a photo back, because a backup nobody has restored from is a setting, not a backup.
    Record what the rehearsal actually cost in wall-clock time; that number is the answer a host
    gets on the day it matters. The dashboard half is Phill's hands, the DEPLOY.md procedure is
    the deliverable anyone can check.
    **What the 2026-09-06 audit established, so the rehearsal starts from facts.** The mechanism
    exists: `cloud database-restore:create` takes either `--snapshot` or `--point-in-time`, and a
    restore is created as a **named restore** rather than an overwrite — which is what makes a
    scratch-environment rehearsal possible without touching production. But **every cluster-level
    read is broken in CLI v0.5.2** (`database:list`, `database-cluster:list` and
    `database-snapshot:list` all send a disallowed `include=schemas` and 400, even addressed by
    name), so the retention window is a canvas read and the rehearsal has to be driven from the
    dashboard or by ID.
    **The object-storage half is smaller than it looks.** `cloud bucket:update` exposes exactly two
    settings — name and visibility — so there is no versioning, lifecycle or object-lock knob to
    confirm or change, and the bucket sits under a Cloudflare account that is not ours. R2
    versioning is opt-in per bucket and Cloud does not expose it, so it is very likely off; that is
    an inference, not a read, and it is one of the two questions in the support ticket above. If it
    turns out to be **on**, 26's deletion clause is wrong as drafted and this becomes urgent —
    "deleted" photos would survive as noncurrent versions.
    **Nothing has ever been swept** (`0` events with `photos_purged_at` on 2026-09-06), which is
    correct rather than alarming: production launched 2026-08-22, so the earliest window closes
    around 2026-11-20 and the sweep follows 30 days later. It does mean the sweep has never run
    against real data, which is exactly what this item exists to fix.

**Should — what a stranger hits on their first night, or the first support ticket.**

31. **A host can look after their own account.** No logged-in route changes a password, changes a
    verified address, or deletes an account, and the privacy policy has to point at something.
    Minimum: a password change form, and account deletion that purges every event
    (`Event::purge()`) and then the user. Changing a *verified* address waits for 22's pattern.
32. **Typeset the caption in the design-system fonts.** The page loads Instrument Serif / Sans and
    DM Mono and the canvas uses none of them, so a Pixel and an iPhone print different strips of
    the same event; start `document.fonts.load()` at module init and await it (short timeout)
    before compose. Small now that the footer's typesetting lives in `strip-footer.ts` — it is its
    `CAPTION_FONT_STACK` plus the await, and the failing test goes in `strip-footer.test.ts`. A
    date line in the footer is *not* here — see 47. **This item used to have a part (a)**, the cell
    resize to 960×720 and the strip's JPEG quality to 0.9; that shipped ahead of it because 53
    cannot publish a pixel size the next slice would move.
34. **Filenames: `{stem}_{YYYY-MM-DD}_{HH-mm-ss}_{strip|photo-N}.jpg`.** The answer to *"album
    name + local datetime?"* is yes, and one scheme for the three places that name the same file
    three ways today: the guest's own save uses the raw event name, undated, so a second strip
    from one event collides; the album's `Content-Disposition` slugs it and appends a row id; the
    zip does a third thing. **The booth's Saves are real downloads on every device now**, so every
    strip from one event lands in one Downloads folder under one name. Untidy rather than
    destructive — browsers suffix the duplicates. Underscores between fields and hyphens inside
    them, zero-padded, so a plain sort is chronological and a session's files stay together;
    `photo-N` is the slot.
    **Two commits.** (1) Small, ship early: `Event::fileStem()` = `Str::slug($name) ?:
    strtolower($code)` — `Str::slug` of an emoji or CJK name is the empty string (confirmed), so
    those events download as `-strip-42.jpg` and `-photos.zip` today; pass the stem to the booth
    as `data-event-stem` so `capture.ts` stops using the raw name. (2) Medium, after one decision
    on where local time comes from: recommend a per-photo `taken_at` + UTC offset sent with each
    upload (not a per-event timezone) — it is the guest's own clock, matches their camera roll,
    and fixes the album and owner page showing **UTC times as if they were local** (the app is
    UTC and nothing converts) in the same change; the archive gets real mtimes and a used-names
    set, because `ZipArchive::addFile` is silent on duplicates.
35. **"All photos" → "Photos".** The answer to *"Originals?"* is no. PLAN.md already defines
    "photos" as the shots a guest took, the header stat one line above says "N photos", and the
    archive folders and the mail say strips / photos — "Originals" would be a third noun for all
    of them to follow. Also the owner page's "strips and originals in their own folders" →
    "strips and photos". One-line `GalleryPageTest` first; PLAN.md and GATE §7 wording in the
    same commit.
36. **A real icon, a manifest, and `theme-color`.** `public/favicon.ico` is 0 bytes and no view
    carries `rel=icon`, an `apple-touch-icon`, a manifest or `theme-color`, so every tab shows the
    generic icon and an accidental Add-to-Home-Screen gets a letter tile or a page screenshot.
    Icon set (192 / 512 / 512-maskable / 180 + a multi-size `.ico`), `public/manifest.webmanifest`
    with `display: browser` and `start_url: /`, and the links in the theme partial with a `$tone`
    variable so the booth reports ink and the paper pages ivory. Pest beside `SearchIndexingTest`;
    one GATE line. **Blocked on D4's artwork**, which Phill approves in the Stripe sitting — a
    manifest pointing at a 0-byte placeholder is worse than none, so leave this parked until the
    icon lands rather than shipping half of it. **This is all of the "add to home screen" item
    that ships** — the button is under Explicitly not doing.
22. Give a host a way to fix their own address — and later, a third gate state. `/email/verify`
    says "ask an admin to change it" and no route exists that lets them, so a typo at registration
    lands a host exactly where the SES sandbox did: dashboard reachable, `/new` barred, no way out.
    **Pre-release: the address-change form** (the first support ticket a public release
    generates), plus the client-side domain-typo suggestion at registration (edit distance against
    the common providers, *suggest* never block, include `bigpond.com`, `optusnet.com.au`,
    `iinet.net.au`) — a pure module. **With 21, not before: the gate's third state.**
    `Deliverability::mailerIsFake()` tests the mailer *name*, so the app knows "no mailer" and "a
    mailer" and nothing between — but it has no signal to read for "a real transport that will not
    deliver to *this* recipient" until 21's webhook records bounces, so building the state first
    is a state with no input.
37. **The booth reports its own errors** — OBSERVABILITY.md phase 2, as specified there: a client
    `report.ts`, `POST /e/{code}/client-errors`, CSRF-exempt, throttled **on the event code**, not
    the IP (a venue is one NAT address). Today the `window.error` / `unhandledrejection` handlers
    only paint the error screen. Sequence before the gate pass; the expired-page throw that would
    otherwise have been its first report is already fixed. OBSERVABILITY's phases 0–1 are
    dashboard work, not slices.
38. **Registration cannot burn the day's mail.** `/register` has a 10/min IP throttle and nothing
    else, and every registration costs a verification mail against Resend's hard 100/day (MAIL.md)
    — a scripted burst of two hundred signups pauses every host mail (resets, verification,
    download-all) for the day. A hidden honeypot field is an hour; a per-day registration ceiling
    is another. Verification already gates `/new`, so this is about the mail, not the events.

**Nice — fillers for Stage 1, or the first week after it.**

39. **The resume notice as a status pill.** The answer to *"more obvious — a toast?"*: not a
    generic toast; there is one consumer. `#resume-notice` sits inside the start screen styled
    identically to the tally beneath it, and `showOnly()` hides it the moment the guest taps
    Start — mid-resume. Move it to body level as a fixed chip in the `.hud-chip` tokens under the
    notch (`env(safe-area-inset-top)`), `role="status"`, a pink dot while in flight and `--ok` on
    landing, a real progress callback ("Finishing an earlier upload… 2 of 4" — `capture.ts`
    passes a no-op today), auto-hide a few seconds after success, failure stays up. GATE §5 on
    both phones.
40. **Shutter sound and countdown tick** (absorbs the old "audio/haptic countdown cue" filler). No
    audio exists in the app. A ~40-line `sound.ts` glue module: one lazy `AudioContext`;
    `unlock()` = create + `resume()` as the first synchronous statement of `enterCamera()`,
    `retake()` and the camera-retry handler — every countdown path enters through one of them
    from a tap, and iOS keeps a context suspended until a gesture resumes it and re-suspends it
    after a lock or a call, so unlock must be idempotent; synthesised tones, no asset; `tick()` on
    each countdown second (silent in landscape because no tick is dispatched), `shutter()` beside
    the flash, `navigator.vibrate?.(30)` for Android. `#again` now dispatches `reset`, so the
    unlock has to happen on the start screen's controls rather than on it. GATE
    §2: audible on both phones, iPhone mute switch honoured.
- **Theme housekeeping** — after launch. Dead rules and tokens in `theme.blade.php` (`.card`, the
  `select` rules, `--orange`, `--r-xl`, `--leading-snug`, a doubled `--shadow-md`), eight views
  duplicating the `.room` shell, a `.fold` component for the owner page. A refactor is never a
  blocker; its only cost is that 26 lands on two more duplicated shells.

### Gate — the combined real-device pass, after the Stage 1 slices

One session on real phones (Android + iPhone) after the Stage 1 slices land — it is no longer the
last thing before the public, because the doors now open with Stage 2, but running it here keeps
the Stripe sitting from starting on top of unverified screens. The full checklist
is **[GATE.md](GATE.md)** — every screen to try, in the order it makes sense to try them, with the
seeded event codes for each state. Discharge the still-open lines (§5 throttled / rejected /
offline hold, §8 PIN persistence and rotation, §9 — reachable on production through the admin date
path that 23 keeps, §10, §11 the expired download link, §12) plus one new line per screen the
slices above changed. Close §12's light/dark line honestly by recording in PLAN.md that the app is
fixed-scheme by design. **Add to it whenever you ship something a phone has never rendered.**
Then re-run only what Stage 2 touched — 41's Checkout lines and anything 30's copy changed —
before the doors open; that second pass is a few screens, not another session.

### Stage 2 — take money; the doors open when this lands, not before

41. **Stripe — money in for one event.** `stripe/stripe-php` directly, **not Cashier**: Cashier's
    value is subscriptions, saved payment methods and a Customer per user — schema and surface for
    a product whose checklist forbids subscriptions — and its `checkout.session.completed` still
    needs a hand-written listener. Neither is installed today. Slice: `events.paid_at`,
    `stripe_checkout_session_id` (unique), `amount_paid` + `currency`; a `services.stripe` block
    from `STRIPE_*`; `POST /events/{code}/checkout` (auth, `managedBy`, refused when paid or
    purged) creating a Checkout Session in `payment` mode with `client_reference_id` = event id;
    `POST /stripe/webhook` outside auth and in the CSRF except list — **the app's first webhook
    of any kind**, so leave the pattern (signature check, tiny idempotent handler) reusable for
    21; **one idempotent `Event::markPaid()` reached from both the webhook and the `?checkout=`
    success URL** — they arrive in either order, Stripe retries for days, and production is
    cookie sessions on scale-to-zero, so never carry checkout state in the session; the window
    becomes `max(existing, now) + paid days`, never a shortening reset; 23's host button becomes
    "Keep the photos for a year — $X"; a `photobooth:check-payments` deploy gate mirroring
    `CheckMail` that refuses blank `STRIPE_*` **or a test-mode key** in production (the
    `config:cache` trap applies to these exactly as to `RESEND_API_KEY`); Stripe sends its own
    receipts (Resend's 100/day cap). Pest with a faked payments wrapper and a real
    `Stripe-Signature` HMAC helper; DEPLOY.md env + gate; a GATE line for Checkout on both phones
    from the owner page. **Settles D1's numbers first** (days, price, GST, the anchor — see
    Decided above), because 23's copy, 30's limits line and the consent window all read them.
    Needs a Stripe account verified for an AU business, and **the doors open when this ships**, so
    it is on the critical path rather than after it.
42. **Comped accounts — "unlimited without admin".** The answer to the open question: once
    entitlement is a per-event window derived from (a purchase OR the owner's plan), *unlimited is
    just the best value of that one attribute*, and this is the `make-admin` mechanism with a
    different column — `users.plan` (nullable string, `'comped'` for now) set by
    `photobooth:comp {email}` via `forceFill`, read in exactly one place, the window resolver that
    23 and 41 share. **Never `is_admin`**: admin is oversight of every event (`Event::managedBy`,
    the dashboard), plan is entitlement — write that sentence into PLAN.md's Owner accounts
    paragraph. Building it before 41 creates a second axis to unwind. Interim for a friend today,
    zero code: Phill is admin and can set their events to "kept for good" from the retention fold.
20. Warn hosts before their retention window closes — a mail at, say, 14 days and 1 day out, and
    one when the photos have actually gone. **Phill's, 2026-08-31.** The window is stated on three
    screens and nowhere else, so a host who never opens the app never hears about it;
    `photos_purged_at` already records the fact the last of those mails would report. Not a
    Stage 1 blocker — the sweep cannot reach a stranger's album for 120 days after they create it
    — but a *paid* window that silently expires is the refund request, so it ships in the same
    window as 41 and before the first charge. Idempotent per event per threshold (scale-to-zero
    replays the schedule), and on the same day boundary as the sweep — dates are stored end-of-day
    UTC, so an AEST album actually closes at 10:00 the next morning local time.
21. Bounce and complaint visibility. Resend's equivalent of the old SES-notifications-to-an-inbox
    arrangement is a webhook (`email.bounced`, `email.complained`, `email.failed`,
    `email.suppressed`), and there is no endpoint for one — so a hard bounce puts an address on
    Resend's account-wide suppression list, every later send to it is accepted and dropped, and
    nothing says so. The list is readable over the API meanwhile; MAIL.md has the check. Build it
    **in the same sitting as 41's webhook** so the pattern is written once, together with 22's
    third state, which is the consumer of what it records. Revisit the priority the day a stranger
    says "no email came".
43. **Marketing `/` and `/pricing` on the canvas** — the one part of "revisit the UI" that needs
    `Redesign.dc.html` reopened, and it cannot show a price before 41. Everything else on
    the UI list is polish that ships inside the slices above without touching the canvas.

### After the first dollar — the participation engine and the bets

Decide the tier each sits in *before* building it: shipping something free and gating it later
violates "limits stated up front".

9. Live wall: full-screen `/e/{code}/wall` for venue TVs — strips animating in via 3–5s cursor
   polling, event QR + code always in a corner, Screen Wake Lock, watchdog reload when polls stall
   (tab-sleep is the #1 documented live-wall failure). **Not a release blocker** — the guest loop
   is complete without it, it is absent from Phill's own before-release list, and it adds the one
   device class the gate cannot test (TV browsers). It **is** the launch differentiator to ship
   first after Stage 2, and the most natural paid-tier feature. Shape: a host-generated **signed
   wall URL** (the archive-link pattern) so the TV never meets the album gate and hidden or PIN'd
   albums can still have a wall; a separate JSON feed `GET /e/{code}/wall/feed?after=` reusing the
   album's `MAX(id)` cursor, **under its own `RateLimiter::for('wall')` keyed on the event code in
   the first commit** — the guest limiters are keyed on the code rather than the client because a
   venue is one NAT address, and a TV polling all night must not starve the guests' strips out of
   the `uploads` bucket at the busiest moment. One TV per event is the shape to size for.
10. Moderation shipped WITH the wall, as one slice: approve/hide per session from the host's phone,
    pending count on the wall. This is the item that forces **a session row** — today a session is
    an implicit `group_uuid` grouping with a `MAX(id)` cursor and there is nothing to hang a flag
    on. Introduce `booth_sessions` (event_id, group_uuid unique per event, `hidden_at`, `mission`,
    `device_token`, timestamps; the framework owns the name `sessions`), `firstOrCreate`d on a
    group's first upload (strip-first ordering makes that the strip), backfilled from `photos` by
    migration, filtered `hidden_at IS NULL` for guests and never for the host. **Default visible,
    one-tap hide** — pre-approval kills participation at exactly the moment the review says
    matters. Missions (44) and the device token (12) want the same row; do not build it before
    this needs it.
12. "My strips tonight", split in two. **D2 locks the own-phone model, so a device token means
    one guest** rather than one kiosk. **Device half first** — the first guest-facing slice after
    Stage 1 and the honest answer to "how does a guest get back to theirs": write the group uuid
    to a per-event `localStorage` list at share time (nothing remembers a device's own groups
    today — the IndexedDB record is forgotten the moment the upload lands), add a "Mine" chip to
    the album that requests `?mine=<groups>` (the album is paged, so a strip from earlier in the
    night may be pages away) and highlight those cards. The store is a safety net, never a
    dependency (private mode). **Email half later**: optional email-me-my-strip with separate
    consent checkboxes (delivery ≠ marketing) — the first feature that mails *guests* at event
    volume, so it waits for a paid mail tier (Resend's 100/day) and for the session row to hold
    the consent; deliver the strip link by mail rather than storing a marketing list.
25. A guest re-gated mid-scroll is told "That's the whole album". **Found reviewing the PIN relock
    slice, 2026-09-05.** The album pager fetches the next page and appends its panels; a gated
    response has none, which it reads as the album having emptied under it, so it calls
    `finish()`. Correct for a host deleting the last session, wrong now that a PIN rotation can
    gate a scrolling guest — they should be sent to the door. Needs the gate response to be
    distinguishable to the pager (a header, or an id on the gate body) and a `location.reload()`
    on that branch. Narrow: it needs a rotation during a scroll of a 24+ session album, and a
    reload already fixes it. Rides along with whichever slice next touches the album foot.
44. **Photo missions** — host-picked prompt packs; a mission deep-links into capture and stamps
    the prompt as the strip caption. Layer 1 is pure client: a `missions.ts` pack
    registry, `Event::MISSION_PACKS`, a nullable `events.mission_pack`, `?m=` validated in
    `capture()` and emitted as `data-mission`, printable per-mission QR cards on the owner page
    reusing `qrSvg()`. Decide first what a mission stamps on an event that has a **logo** — today
    the logo replaces the caption wholesale; recommend a header band above the cells, accepting
    the strip-size change and its seeder mirror. Layer 2 (label and group by prompt in the album
    and on the wall) needs the session row from 10.
45. **9:16 story strip** — about twenty pure lines: draw the composed strip canvas centred on a
    1080×1920 theme-coloured canvas, built in `prepareStripShare()` beside the strip blob so the
    later tap keeps its user gesture (the code already does this for the strip), offered as one
    extra ghost control on review and done. **Phone-only, never uploaded** — the album keeps one
    strip per session, storage cost is zero, the server is untouched. GATE: Instagram as a share
    target on both platforms; memory on a low-RAM phone.
46. **Boomerang / GIF.** When it is built, gifenc-in-a-Worker as the **only** path, not a fallback:
    one output format, `<img>` everywhere, GD thumbnails work on the first frame, no transcode, no
    `<video>` in the album. Capture frames with the existing `grabFrame()` on a timer rather than
    MediaRecorder, so filters and the un-mirrored capture rule come free; a `recording` flow state
    with `cameraLost` coverage and tests. Any new `photos.kind` must also touch
    `BuildEventArchive` (it hard-codes `['strip','original']` and throws on a third) and gate the
    thumbnail dispatch. The item most likely to burn a week on device quirks.
47. **A date line in the strip footer** — with 44, once `strip-footer.ts` has settled (32 is the
    part of it still to land): it changes `footerHeight` and so every strip's height and its
    mirrors, and shares the
    logo-conflict decision with missions. Recommend date + caption (caption serif, date DM Mono),
    the logo replacing the caption line only, and no event code on the strip. The filename (34)
    already dates the saved file.
48. **Audio guestbook**, then separately **video guestbook.** Its own table, page and mic flow —
    the camera stream is video-only by a locked decision, so audio is a second on-demand stream,
    not a change to the first. Video is a different product with a different backend (chunked
    uploads, transcode) and stays unscheduled until someone is paying for it.
- **P5 bets — Phill picks the direction first.** Consumer: the template/frame library as
  versioned data, whose first commit is moving `TEMPLATES` + `STRIP_THEMES` into one JSON both PHP
  and TS read (that alone removes the hand-sync and gives server-rendered swatch pickers as a
  by-product), then frame art one field at a time, AI portrait strips (server-side queued jobs;
  never meterable mid-event), delayed album reveal, and the event cover photo (copy the logo
  pipeline exactly, one `cover_path`, plus a derivative so a 5 MB upload is not every guest's
  start screen). Enablers: the WebGL2/LUT capture pipeline (`ctx.filter` is confirmed never coming
  to iOS), printing via PrintNode/Prodigi — a 2×6 at 300 dpi is a new cell aspect and therefore a
  new template, and cannot be finished without a verified SKU. Album session cards on the canvas
  sit here too.
- **Anytime fillers**: filter-change-at-review (keep raw frames, apply the colour matrix at
  compose) · back-camera toggle + higher capture resolution (raising the camera `ideal`) · theme
  housekeeping (above) · a host's session delete sending them back to page 1 · the owner-page
  bookmark for a deleted event showing guest copy.

### Explicitly not doing
- **Per-shot retakes** — product decision (2026-08-26): the booth models a real photobooth,
  and a real booth doesn't let you retake a frame. Don't "fix" the all-or-nothing retake.
- **An "add to home screen" button.** Every locked decision says no-install; iOS has no install
  API (only Share → Add to Home Screen, so a "button" there is instructions); and a home-screen
  instance on iOS is a separate storage partition — the PIN unlock, the IndexedDB resume and any
  device token minted in Safari would not cross into it, silently breaking "an interrupted share
  finishes itself". The problem the item named ("get back if I close out") already has three
  answers that work for every guest — the poster, the shared link, the code — and "find mine" is
  item 12. The cheap half (36) ships.
- **Full-bleed camera preview** from the canvas — it breaks the WYSIWYG promise GATE §2 checks.
- **Mat colours following the strip theme** — reverses a documented decision; the mat is page
  chrome and never part of the saved JPEG.
- "Originals" as the album tab label · a generic toast component · Cashier · `is_admin` as the
  vehicle for "unlimited" · an admin UI.
- **A guest-facing "delete my photo" control** (2026-09-06, PLAN's locked decisions). Not an
  oversight and not a gap in the legal floor: the host already deletes a session from the album and
  an admin can delete any of them, so the mechanism exists and a person operates it. Building a
  guest-facing one before item 12's device token would be *unsafe* — nothing on a photo row
  identifies a guest, so the only gate available is the album link that every guest already holds,
  and it would let anyone delete anyone's photos. Revisit **with 12**, never before.
- Face search (AU Privacy Act sensitive-information obligations; low value for a booth).
- Admin impersonation — revisit only when third-party owners need hands-on support.
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
