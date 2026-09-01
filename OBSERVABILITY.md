# Observability — seeing production break before a host tells us

The plan and decisions for tracking errors, logs and (eventually) usage in production.
[HANDOVER.md](HANDOVER.md) is the map of the app; this file is how we find out what the app did
last night. **Status: Phases 0 and 1 are live (2026-09-01)** — the `cloud` CLI is installed and
authenticated on the dev machine, the agent skill is in, Claude reads production logs directly,
and the missing `check-mail` deploy guard is in the environment's deploy commands. The Nightwatch
integration is connected and the agent runs on the App cluster; a deliberate smoke-test exception
fired on prod was captured as an issue, **emailed Phill by default**, and read back through the
log stream. One formality open: the first MCP read-back (the server loads at session start, so a
session newer than the hookup does it, then resolves the smoke-test issue). Phases 2–3 not
started. Update this line as phases ship.

All vendor facts below (pricing, tiers, MCP endpoints, API shapes) were verified against current
docs on 2026-09-01 and independently fact-checked. Re-verify prices before acting on them if
you're reading this much later.

## The goal, and the constraints

Three, in order:

1. **Nothing new on the guest's phone.** The booth pages are the product; every KB of JS is paid
   for on venue Wi-Fi. Everything in this plan adds at most ~1KB (Phase 2), most of it adds zero.
2. **Claude reads production itself** — errors, stack traces, logs, failed jobs — instead of
   Phill pasting them into chat. Pasting stays as the fallback, not the workflow.
3. **Boring and near-free.** Solo dev, bursty event traffic, cost-sensitive. Every phase below is
   $0 at current traffic.

## Where we are today

- **Server errors** flow through the platform-injected `laravel-cloud-socket` channel into the
  Cloud dashboard's Logs tab — with stack traces, searchable. But **retention is short**: 1 day
  on the Sandbox/Starter plans, 7 days on Growth ($20/mo), 30 on Business. An error from Saturday
  night can be gone by Monday. (Confirmed 2026-09-01: production is on a 1-day tier — the logs
  API refuses a 24-hour query outright.)
- **Nobody is told.** Cloud's notifications cover failed deploys, failed commands, cluster health
  and spend — there is **no alert for an application exception**. A 500 during an event is silent
  until someone goes looking.
- **The booth's client errors vanish.** The global handlers ([capture.ts:499](resources/js/capture.ts:499))
  show the guest an error screen and report nothing anywhere. The app's riskiest code — camera
  glue, iOS Safari quirks, the upload queue — fails invisibly, on a device we'll never see.
- **Queue failures are visible** (Cloud dashboard → Monitoring → Queues → Failed jobs, with
  exception + stack trace, retry/delete) — but again, only if someone looks.

## Phase 0 — hand Claude the platform's own eyes (no code, ~15 minutes)

Everything here already exists on the Cloud plan we pay for; it just needs credentials on the dev
machine. After this phase, "check prod for errors" is a thing Claude does, not a thing Phill does.

1. **Install the official CLI**: `composer global require laravel/cloud-cli`, then `cloud auth`
   (browser OAuth). Key commands:
   - `cloud environment:logs --hours=12 --json` — application logs, and exception entries carry
     class/file/full trace even with `APP_DEBUG=false`. Also `--from/--to/--tail/--live`.
   - `cloud tinker --code="..."` — non-interactive queries against the live environment.
     (A prod REPL: read-only by convention, and Claude asks before anything that writes.)
   - `cloud deploy:monitor`, deployment logs, metrics — all with `--json`.
2. **Install Laravel's official Claude Code plugin** so Claude knows the CLI without being taught:
   `/plugin marketplace add laravel/agent-skills`, then install `laravel-cloud@laravel`.
3. **Optional, for raw scripting**: a bearer token (Cloud UI) against the REST API —
   `GET https://cloud.laravel.com/api/environments/{env}/logs` (requires `from`/`to`, supports
   `query` search and `type=application|access`). Failed queue jobs and metrics have endpoints too.

What this still doesn't give us: **durability** (retention above — on a 1-day plan the evidence
of a bad night evaporates) and **alerting** (nothing pings anyone). Both are Phase 1's job. Treat
the token/auth as secrets; they can read prod and trigger deploys.

## Phase 1 — Nightwatch, the error tracker (config + one composer package)

**Laravel Nightwatch** (nightwatch.laravel.com) is the recommendation. Sentry is the runner-up —
the Decision log below has the full reasoning; the short version is that Nightwatch is the
first-party path on this exact platform and its free tier fits this app comfortably.

What it buys us, in this app's terms:

- **Every exception, kept 14 days** (free tier), with stack trace, occurrence counts and user
  impact — the durable record the 1-day platform logs aren't. Exceptions are captured at 100%
  even though requests are sampled.
- **The queue and the scheduler become visible.** `BuildEventArchive` failures, `GenerateThumbnail`
  failures, and — the one that matters most — the retention sweeps. A silently failing
  `photobooth:sweep-expired` makes the retention window a promise the deploy isn't keeping;
  Nightwatch monitors scheduled tasks as first-class events.
- **Alerting exists, and the useful one is on by default**: a new issue emails the account owner
  with no setup (verified by the 2026-09-01 smoke test — the mail beat the manual check). Slack
  integration and a per-application webhook (`issue.opened` etc.) are the opt-in upgrades, under
  Settings → application → Issues.
- **Claude reads it directly** via the official MCP server:
  `claude mcp add --transport http nightwatch https://nightwatch.laravel.com/mcp` (OAuth on first
  use; or install the `laravel-nightwatch@laravel` plugin from the same marketplace as Phase 0).
  Tools: list applications, browse exception **and** performance issues, full stack traces with
  timing data (route duration, query times, job metrics), update issue status, add comments — so
  Claude can pull the trace, fix the bug, and resolve the issue without a paste.
- **Logs flow there too.** The Cloud integration sets `LOG_STACK=laravel-cloud-socket,nightwatch`,
  so app logs (including Phase 2's client error reports) land in both the Cloud log viewer and
  Nightwatch's log search.

Setup (mostly dashboard clicks; the composer require is the only repo change):

1. `composer require laravel/nightwatch` (prod dependency).
2. Create the Nightwatch app (free tier), copy its token. There's a Sydney region.
3. Cloud environment dashboard → **Connect Nightwatch** → enable monitoring → paste token.
   Cloud then runs the agent on all App/Worker compute itself and injects `NIGHTWATCH_TOKEN`,
   `NIGHTWATCH_REQUEST_SAMPLE_RATE=0.1`, `LOG_CHANNEL=stack`, `LOG_STACK=laravel-cloud-socket,nightwatch`.
4. Alerting needs nothing: new-issue email is on by default. Add the Slack integration or a
   webhook later if email stops being enough. A spending cap only matters once a payment method
   is attached — with none on file, hitting the free quota pauses ingestion on its own, which is
   the failure mode a $0 cap exists to guarantee.
5. Add the MCP server to Claude Code (command above).

Cost guardrails — the free tier is 300k events/month, but **everything is an event** (each
request, query, job, cache op, log line): requests default to 10% sampling on Cloud, exceptions
and commands stay at 100%, and a 4000-photo night lands comfortably inside the free quota at those
rates — but check the usage meter after the first big event before trusting that. Free-tier
overage is $0.50/100k (hence the $0 cap); the paid escape hatch is Pro at $20/mo for 7.5M events.
One quirk to know: **free projects pause after 30 days of app inactivity** — the nightly sweeps
should keep the app warm through idle months, but glance at it after the first quiet one.

Known gap: the MCP is **issue-centric**. Raw log search and arbitrary per-request traces are
dashboard-only (as of 2026-09). The reading order when something breaks: Nightwatch MCP (the
issue + trace) → `cloud environment:logs` around the timestamp (correlate access logs, client
reports) → dashboard/paste as the last resort.

## Phase 2 — the booth reports its own errors (the one real code slice)

The riskiest code in this app doesn't run on the server. Camera acquisition, `ctx.filter`
fallbacks, IndexedDB in private-mode Safari, the upload retry tail — all of it fails on a phone
we can't see, showing an error screen to one guest and telling no one. This phase makes the
existing handlers report before they render.

Not an SDK, and here is the whole trade laid out (settled with Phill, 2026-09-01). Nightwatch has
**no browser SDK**, so "use an SDK" doesn't mean upgrading this reporter — it means adding Sentry
as a second vendor (account, quota, dashboard, MCP) solely for client errors, plus its errors-only
bundle, which **measures 30KB gzipped** against a booth whose entire JS is ~7KB, delivered at the
QR-scan moment on venue Wi-Fi. The tiny third-party alternatives speak Sentry's deprecated
ingestion protocol. A hand-rolled reporter is ~0.5–1KB, testable, and feeds the pipeline Phase 1
already alerts from. What it honestly gives up: transport robustness (a beacon that fails is a
lost report — though an SDK's standard error transport is also send-once; offline buffering is an
opt-in extra even in Sentry) and automatic symbolication (Claude decodes minified frames from the
build's maps on demand instead — minutes per novel error, at a volume of a handful per event
night). **The switch trigger:** if client errors prove frequent, or undiagnosable from message +
stack + flow-state, or session replay is ever wanted — add the Sentry browser SDK *then*, with
evidence. Nothing here gets undone: the endpoint stays, the SDK is additive.

Design (a normal slice: red/green TDD, then adversarial review):

- **Client** — a small pure module (`report.ts`, Vitest-tested) called from the two existing
  global listeners in [capture.ts:499](resources/js/capture.ts:499) and wired onto the other guest
  pages (album, gallery unlock):
  - Payload: `{message, stack, url, userAgent, eventCode, flowState, landed, ts}` — message and
    stack as **separate fields** (Chrome and iOS Safari format stacks differently; treat the
    stack as an opaque string), stack truncated at 8KB. `flowState` (the capture-flow state
    machine's current state) and `landed` (uploads already in the album) are this app's answer to
    an SDK's generic breadcrumbs: for debugging a booth, *which screen and how much was saved*
    beats "user clicked something 4 seconds ago".
  - Noise control, all client-side and unit-testable: fingerprint = message + first stack frame,
    deduped in a per-pageload `Set`; hard cap ~5 reports per page load; drop frames from
    `*-extension://` URLs; `String()` non-Error rejection reasons.
  - Transport: `navigator.sendBeacon` (JSON blob, well under its 64KiB limit), falling back to
    `fetch(..., {keepalive: true})` when sendBeacon refuses. Fire-and-forget — **no offline retry
    queue**; reports are best-effort by design, and that's exactly the defensive complexity this
    codebase avoids until reality demands it.
- **Server** — `POST /e/{code}/client-errors`: throttled (its own bucket, ~10/min/IP), CSRF-exempt
  for the same reason the uploader is (no authenticated session to protect; booth pages sit open
  for hours), payload size-capped, always answers 204. It does one thing:
  `report(new ClientError($payload))` — a small exception class carrying the report — so a client
  error rides the exact pipeline a server exception does: it becomes a **Nightwatch issue**,
  grouped by message, kept 14 days, **emailed on first occurrence** (the smoke test proved that
  path), and readable by Claude through the MCP. Reported exceptions are logged too, so it still
  appears in the Cloud log stream beside the access logs it correlates with. Zero new storage or
  vendor. Scoping it under the event code means a report names the event it came from, and an
  unknown code is refused.
- **Symbolication** — set Vite `build.sourcemap: 'hidden'` so maps are emitted without a
  `sourceMappingURL` pointer. The maps sit beside the build output and are technically fetchable
  by guessing the content-hashed filename + `.map`; acceptable, because they contain only client
  code every browser already downloads. Content-hashed filenames make frame→map lookup
  deterministic, so Claude can decode a minified stack on demand with a few lines of node and the
  `source-map` package.

## Phase 3 — the analytics seed (optional; only after 0–2 have proven themselves)

Most product questions are **already answerable from the domain tables** — sessions and photos
per event, share times through the night, archives requested, events created per host — via
`cloud tinker` from Phase 0. What no table records is *attention*: booth pages opened, albums
viewed, unlock attempts. The lightest thing that fills that gap:

- A `usage_events` table (`name`, nullable `event_id`, small JSON `meta`, `created_at`) — plural
  and boring on purpose; `events` is taken by the domain. One inline insert at each interesting
  moment; no queue at this traffic (revisit only if it ever shows up in guest-request latency).
- Bot-filter only the GET-shaped events (booth opened, album viewed) with a crawler user-agent
  check; the POST-shaped ones (upload, unlock, archive request) are already bot-proof in practice.
- Zero JS, zero vendors, $0 — and Claude queries it the same way it queries everything else.

If dashboards are ever wanted without building them: **Pirsch** ($6/mo — the only vendor with an
official *server-side* Laravel package, plus a queryable REST API) or **Umami Cloud** (free to
100k events/mo, server-side `POST /api/send` + a stats API). Not Pan (client-JS injection,
counters only), not Plausible (stats API gated to the $19/mo plan), not GA.

## Decision log — why not X

- **Sentry** — the runner-up, and a close one. Richer MCP (~50 tools: natural-language issue/event
  search, traces, spans, breadcrumbs — where Nightwatch's is issue-centric), 5k errors/mo free
  with 30-day retention, `sentry-laravel` 4.27 supports Laravel 13/PHP 8.4, tracing off by
  default. Passed over because: wiring is manual where Nightwatch is a platform toggle; scheduled
  tasks need per-task `->sentryMonitor()` and the free plan includes exactly **one** cron monitor
  (we have two sweeps and counting); and its standout strengths — the browser SDK, deep event
  search — are things this plan deliberately doesn't use. **Switch trigger:** if Nightwatch's
  issue-centric MCP proves too shallow for real debugging, move — Phases 0/2/3 carry over
  unchanged, and the free tiers make it a low-stakes swap.
- **Flare** (€9/mo) — the deepest Laravel context, and its MCP + CLI + agent skill are built for
  exactly this "agent reads prod" workflow (they removed their own AI feature in favour of it).
  No free tier, and Nightwatch covers the need at $0.
- **Honeybadger** — best free tier among the independents (5k errors/mo + an uptime monitor,
  official token-auth MCP). The pick if we ever want out of the Laravel-ecosystem tools entirely.
- **GlitchTip** — hosted free tier is 1k events/mo (one looping bug at a 4000-photo event eats
  that in minutes); self-hosting it is cheap (~$5 VPS) but running servers is the thing Laravel
  Cloud exists to not do — and the error tracker is the one service that must stay up when the
  app is down.
- **Self-hosted Sentry** — 16GB RAM, 40+ containers. No.
- **A Slack log channel as poor-man's alerting** — viable and $0 (`LOG_STACK` stacks fine with
  `laravel-cloud-socket`), but Nightwatch's alerting supersedes it. Back pocket if Nightwatch is
  ever dropped.
- **Browser error SDKs** — 20–30KB gzipped against constraint #1. Measured, not guessed.

## When something breaks — the runbook once 0–1 are live

1. Nightwatch alert fires (or a host emails). Ask Claude: *"check Nightwatch"* — the MCP pulls
   the issue, trace and occurrence counts.
2. Claude correlates around the timestamp: `cloud environment:logs` for the request's access-log
   line and any `client-error` reports from the same event code; the Queues dashboard/API if a
   job is implicated.
3. The fix is a normal slice: **failing test first** (the bug→test rule), fix, deploy, then
   resolve the Nightwatch issue via MCP so the ledger stays honest.

## Cost summary

| Phase | Adds | Cost |
|---|---|---|
| 0 — Cloud CLI + plugin | nothing to the app | $0 (plan already paid; check retention tier) |
| 1 — Nightwatch | 1 composer package, dashboard config | $0 (free tier, $0 spending cap) |
| 2 — client reporter | ~0.5–1KB guest JS, 1 route | $0 |
| 3 — usage_events | 1 table, inline inserts | $0 |
