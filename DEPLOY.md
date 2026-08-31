# Deploying to Laravel Cloud

The app is portable by construction (standard Laravel, all file writes through the `Storage`
facade, plain Postgres, no serverless code). Deployment is almost entirely **platform config**.

Two Composer packages exist purely for this platform and must stay in `composer.json`:
`league/flysystem-aws-s3-v3` (Laravel's S3 driver, which Cloud's object storage speaks) and
`aws/aws-sdk-php` (required by Cloud's managed queues — a deploy that provisions one without it
fails outright). Neither is used directly by any app code.

## Environment variables (Laravel Cloud dashboard)

Most of what this app needs is **injected by attaching a resource**, not typed in by hand. Set
only these yourself, under the environment's *Environment variables*:

```
APP_NAME=Quikbooth
APP_ENV=production
APP_DEBUG=false            # flip to true briefly if you need to read a real error
APP_KEY=                   # generate one (Cloud can, or `php artisan key:generate --show`)
APP_URL=https://your-domain   # important: QR codes + invite links use this / the request host
CACHE_STORE=database       # throttle state and the sweep's onOneServer lock are shared, so not `array`
```

Everything else arrives on its own, and **is not worth setting by hand — a custom variable
overrides an injected one**, which is how you end up with a live app pointed at the wrong place:

| Injected | By | Notes |
|---|---|---|
| `DB_CONNECTION=pgsql`, `DB_HOST`, … | attaching a Postgres database | |
| `FILESYSTEM_DISK=<your disk name>` + `LARAVEL_CLOUD_DISK_CONFIG` | attaching an object storage bucket | see below |
| `QUEUE_CONNECTION=cloud` + `LARAVEL_CLOUD_MANAGED_QUEUES_CONFIG` | creating a managed queue | see below |
| `LOG_CHANNEL=laravel-cloud-socket` | the platform | |

**Nothing injects a mailer** — `MAIL_MAILER` and its credentials are yours to set by hand, and
`photobooth:check-mail` fails the deploy until they are. See **Mail (password reset)** below.

`SESSION_DRIVER` is whatever you choose; production runs `cookie`, which suits serverless (no
shared store to reach, nothing to clean up). `database` works too — the `sessions` table exists.

### Object storage — the one that keeps guests' photos

Attach a bucket from the environment's canvas ("Add bucket"), choose **Laravel Object Storage**,
and give it a **disk name** — that name is what lands in `FILESYSTEM_DISK`, so a bucket named
`private` yields `FILESYSTEM_DISK=private`. Mark it the **default** disk and choose **Private**
visibility: every photo and logo is served through the app (`Storage::response()`), so nothing
needs to be publicly readable. Redeploy afterwards.

The app never names a disk — every write is `Storage::put()` / `->store()` on the default — so the
disk name is entirely yours to pick and no code changes with it.

> **This is the setting that matters most.** Laravel Cloud's filesystem is ephemeral: it resets on
> every deploy and each replica has its own. With no bucket attached, `config/filesystems.php`
> falls back to `local` and **every photo a guest takes is written to disk that dies with the
> container** — silently, with no error anywhere. That is not a hypothetical; it is how this app
> ran until a bucket was attached. Never run an environment without one.

## Build + deploy commands

Build (Cloud auto-detects PHP + Node):

```
composer install --no-dev --optimize-autoloader
npm ci && npm run build          # MUST run — produces public/build/manifest.json for @vite
```

Deploy (run on every release, after build):

```
php artisan photobooth:check-storage
php artisan photobooth:check-mail
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

`photobooth:check-storage` runs first and exits non-zero if the default disk is a local one on a
deployed environment, or if the bucket won't return bytes it was just handed. It exists because the
alternative — writing every guest's photos to a disk that dies with the container — produces no
error at all, and you find out weeks later. Deploy commands run on the real infrastructure just
before a release goes live, so it sees the same disk the app will.

> Laravel Cloud's docs don't say whether a failing deploy command aborts the release, so verify
> that once. Either way the failure is loud in the deployment log, and the command can be run
> any time from the environment's **Commands** tab.

`photobooth:check-mail` is the same idea for the mailer, and exists for the same reason: the
framework's default mailer is `log`, which accepts everything and delivers nothing, so a host is
told "check your email" and waits for a link that was written to a file. It fails a release whose
mailer is `log` or `array`, or whose `MAIL_FROM_ADDRESS` is still the framework's `@example.com`
placeholder — an address on a domain the transport cannot send from bounces, which from the host's
side is indistinguishable from having no mailer. `--to=you@example.com` also sends a real message,
which is the only way to tell working configuration from working credentials.

The password-reset pages ask the same question at **request** time: with no real mailer the
forgot-password page says so plainly instead of offering a form, the endpoint behind it answers
503, and the login page stops linking to it at all. Nothing else in the app sends mail, so nothing
else changes.

The upload path asks the same question on **every request** and answers 503 rather than writing a
photo somewhere that dies — a refused upload is retried by the phone and costs nothing, a silent
201 costs the guest their strip. The deploy gate alone isn't enough: a bucket can be detached long
after a release went out.

To ask whether anything has *already* gone missing (one lookup per photo, so run it by hand rather
than on deploy):

```
php artisan photobooth:check-storage --photos
```

It reports how many photo rows point at a file the disk doesn't have, names the first ten, and
exits non-zero if there are any.

> **`config:cache` captures env at cache time.** If you change env vars (e.g. attach the database
> or set `DB_CONNECTION`) you must **redeploy** (or `php artisan config:clear`) so the cache picks
> them up — otherwise the app keeps running with the stale/default values.
>
> Laravel Cloud's own docs prefer `config:cache` in the **build** step rather than the deploy step.
> Either works here: the disk and queue that matter are configured at *runtime* by the framework
> from `LARAVEL_CLOUD_DISK_CONFIG` / `LARAVEL_CLOUD_MANAGED_QUEUES_CONFIG`, so they override
> whatever a cached config says.

Do **not** add `php artisan optimize:clear` or `storage:link` to either list — Cloud's docs call
both out as harmful or pointless there (the symlink cannot persist on an ephemeral filesystem).

Do **not** run `php artisan db:seed` in production — the demo seed is guarded to `local` and will
no-op, but there's no reason to invoke it.

## The queue (album thumbnails)

Every upload dispatches a `GenerateThumbnail` job: it writes a 480px-wide derivative beside the
original and records it on the photo row, and the album grids ask for that instead of the full
file. A composed strip is a fixed size whatever the phone
took it on — 648px wide for the single-column templates, 1272px for the 2x2 grid — so the saving
there runs from about half the bytes to a fair bit more; a camera frame is as large as the phone's
camera made it, and shrinks much further. Across a busy album's two tabs that is tens of megabytes
a guest doesn't download.

Photos that were uploaded **before** this shipped have no derivative, and their grid tiles serve
the original (that's the designed degradation, not a failure). To generate the backlog once, on the
environment's command runner:

```
php artisan tinker --execute="App\Models\Photo::whereNull('thumb_path')->each(fn (\$p) => App\Jobs\GenerateThumbnail::dispatch(\$p));"
```

(Run it from the environment's **Commands** tab.)

**Something has to run the jobs.** Production uses a Laravel Cloud **managed queue**: create one
from the environment canvas ("Add compute" → "Managed queue") and deploy. Cloud provisions it,
injects `QUEUE_CONNECTION=cloud`, and runs and autoscales the workers itself — there is no worker
process to configure and no `queue:restart` to run. The queue's name only matters if the app
dispatches to a named queue, which it doesn't: it dispatches to the environment's default.

Two things worth knowing when you create it:

- **Give it more than the default 256 MiB.** GD decodes a photo before it resizes it, so a 4000×3000
  camera frame briefly costs ~48 MB of bitmap plus overhead. If thumbnails stop appearing and the
  logs say "allowed memory size exhausted", that's this — the queue's Memory chart confirms it.
- The Flex class caps a job at 90 seconds, which these jobs (~40 ms) never approach.

### The second job: download-all archives

`BuildEventArchive` is a much heavier job than a thumbnail, and it runs on the same queue. It zips
an event's strips and originals into one file under the event's own prefix, then emails the host a
signed link. Three things it needs:

- **`ext-zip`.** It is declared in `composer.json`, so a runtime without it fails the **build** with
  a plain message rather than failing every archive job at run time. Laravel Cloud lets you add PHP
  extensions per environment if it is not there by default.
- **Local disk while it runs.** Each photo is streamed to a temp file and the zip is assembled from
  those, so the container needs room for roughly twice the event's bytes, briefly. Memory is flat —
  one photo at a time — so the 256 MiB ceiling that bites the thumbnail job does not bite this one.
- **Time.** The job's timeout is 900s. Measured locally against the 4000-photo seed
  (`--class=BigEventSeeder`, event `NEWYRS`): **8.5s, 50 MB peak, a 149 MB archive**. Do not read
  that as a production number — that run was against the local disk. On object storage every one of
  those ~4000 files is a network round trip, so budget minutes, which is what the timeout is for.

  Those 8.5 seconds were **over ten minutes** before the entries were switched to
  `ZipArchive::CM_STORE`. Deflating a JPEG spends real CPU to save almost nothing, and a busy night
  is thousands of them; the archive is a container, not a compressor.

Archives are offered for `Archive::LIFETIME_DAYS` (7) and then deleted by a scheduled sweep — see
the scheduler section. They also go whenever the photos they hold go, because they live under the
same `events/{id}/` prefix that the host's delete and the retention sweep already clear.

Failed jobs appear in the environment's **Queues** dashboard under Monitoring, with retry and
delete — the `queue:failed` / `queue:retry` commands don't work against managed queues.

Nothing breaks without a queue: uploads still succeed and the grids serve full-size originals,
which is the designed degradation. Every guest just pays for it in bandwidth.

> **If you'd rather not use a managed queue**, leave `QUEUE_CONNECTION` at its `database` default
> (the `jobs` and `failed_jobs` tables already exist) and add a background process running
> `php artisan queue:work --tries=3` on the App cluster. It shares CPU with web traffic and gives
> you no failed-job visibility, which is why production doesn't do this.

## Mail (password reset)

**Laravel Cloud has no mail service to attach and injects nothing** — unlike Postgres, object
storage and queues, this one is entirely yours to configure. Until it is, `config/mail.php`
defaults to `MAIL_MAILER=log`.

Production uses **Amazon SES**, chosen because `aws/aws-sdk-php` is already a dependency (Cloud's
managed queues require it), so the transport costs no new package at all. Setup, once:

1. **Verify a sending domain** in the SES console, in the region you intend to send from, and add
   the DKIM records it gives you to that domain's DNS. A verified *address* also works and is
   quicker, but then every host sees mail from that one address and nothing else on the domain is
   authenticated.
2. **Leave the sandbox.** A new SES account can only send to addresses it has verified, which means
   password reset works for you and silently fails for every real host. Request production access
   from the console; it is a form and usually same-day.
3. **Create an IAM user** with permission to `ses:SendRawEmail`, and take its access key and secret.
4. Set these in the environment (Cloud dashboard → environment → variables), then **redeploy** so
   `config:cache` re-reads them:

```
MAIL_MAILER=ses
MAIL_FROM_ADDRESS=hello@your-verified-domain
MAIL_FROM_NAME=Quikbooth
SES_KEY=...
SES_SECRET=...
SES_REGION=ap-southeast-2      # must be the region the identity was verified in
```

> **`SES_*`, not `AWS_*`.** Laravel ships `config/services.php` pointing SES at
> `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`, which on this app are the object-storage bucket's.
> They are deliberately separated here: one rotation would otherwise take out either photos or
> password resets with no obvious connection between the two, and the region that suits a bucket is
> not necessarily one where a sending domain is verified.

Then prove it end to end from the environment's **Commands** tab — configuration being right and
credentials working are different questions:

```
php artisan photobooth:check-mail --to=you@example.com
```

It has to actually arrive. SES will accept a message and drop it if the from-domain is not
verified, and the command cannot see that.

**Locally, leave `MAIL_MAILER=log`.** The reset link is written to `storage/logs/laravel.log`;
grep it out and paste it into the browser. The guard exempts `local` and `testing` precisely so a
dev with no SES credentials can still work on these pages.

## The scheduler (the retention sweep)

Every event carries a **retention window** (`events.photos_expire_at`, 90 days on a new event, and
the host can move it or clear it from the event page). When it passes, the album turns into an
expired page for guests. **Thirty days after that** (`Event::PURGE_GRACE_DAYS`) a scheduled sweep
deletes that event's photos and their files, keeps the event row so its code keeps explaining
itself, and stamps `photos_purged_at` — after which no date brings the album back, and the host
page says so instead of offering an extension.

The gap between the two dates is the point: it is the window in which a host who has already missed
their date can email and be given more time, and `photos_purged_at` is what stops the app promising
that after there is nothing left to give.

**Nothing runs any of it until you turn the scheduler on.** Click the environment's **App compute
cluster** in the infrastructure canvas, enable the **Scheduler** toggle, then save and **re-deploy**
that cluster. After the deploy, Cloud invokes `php artisan schedule:run` every minute. (A Worker
cluster can carry it instead if you'd rather keep it off the web instances.)

Three things Cloud's scheduler does that matter here:

- **It runs on every replica.** `routes/console.php` therefore schedules the sweep with
  `->onOneServer()`, so a scaled environment doesn't delete the same album from several instances at
  once. That method needs an atomic cache lock, which is why `CACHE_STORE` must not be `array` —
  the same reason the throttles need it.
- **The schedule is read at deploy time.** Cloud stores the output of `schedule:list` on each deploy
  and uses it to decide when to wake a sleeping environment, so **a change to the schedule does not
  take effect until the next deployment**.
- **Scale-to-zero wakes for it.** The environment wakes to run the sweep and then stays up for its
  sleep timeout. Daily at 03:15 costs one short wake a night; don't schedule anything at an interval
  shorter than the sleep timeout or the environment will never sleep again.

The second scheduled command, `photobooth:sweep-archives`, deletes built download-all archives
whose link has expired (7 days). Each is a second copy of an entire event, so without it every
download a host ever asked for accumulates on the bucket.

Check what's registered, and what it would do, from the environment's **Commands** tab:

```
php artisan schedule:list
php artisan photobooth:sweep-expired
php artisan photobooth:sweep-archives
```

The sweep is safe to run by hand and prints one line per album it took (or `Nothing to sweep.`). It
only ever picks up events whose window **and** grace have both passed and that still have photos.

Nothing breaks without a scheduler — albums still expire on their date, so guests see the expired
page and hosts keep their controls. The photos simply never get deleted, which means the retention
window is a promise the deploy isn't keeping, and storage grows forever.

## First deploy: create your admin

Registration can't grant admin (by design — no self-escalation), and the demo seed is local-only,
so production starts with no admin. After the app is up:

1. Register yourself through the UI (`/register`).
2. In Cloud's command runner (or SSH): `php artisan photobooth:make-admin you@example.com`

You're now an admin and can see/manage every event.

## Troubleshooting the usual first-deploy failures

| Symptom | Cause | Fix |
|---|---|---|
| `Database file at path [.../database.sqlite] does not exist` / `Connection: sqlite` in production | No Postgres attached, so the app fell back to the SQLite default | Attach a Postgres database (Cloud injects `DB_CONNECTION=pgsql` + creds), **redeploy** so config re-caches with the DB env, then `php artisan migrate --force` |
| Booth / create / owner / dashboard pages 500, but `/`, `/login` load fine | Vite manifest missing — `npm run build` didn't run | Ensure the build step runs `npm run build` and produced `public/build/manifest.json` |
| 500 on **every** page | `APP_KEY` unset, or migrations haven't run | Set `APP_KEY`; confirm `php artisan migrate --force` is in the deploy commands (temporarily set `APP_DEBUG=true` to read the exact error) |
| "could not find driver" / "table not found" | DB not attached / `DB_CONNECTION` wrong / migrations not run | Attach Postgres, `DB_CONNECTION=pgsql`, run `migrate --force` |
| A booth link 404s in production (e.g. `/e/party2`) | `PARTY2` is the **local** demo event; the seed is local-only, so production has no data yet | Register at `/register`, create a real event, then `photobooth:make-admin you@…` |
| Uploads work but photos disappear after a redeploy | No bucket attached, so the default disk fell back to the ephemeral `local` disk | Attach an object storage bucket and mark it default, then redeploy. **Photos written before that are gone.** |
| A deploy fails complaining about `aws/aws-sdk-php` | A managed queue is being provisioned and the package isn't in `composer.lock` | It's a required dependency of this app — don't remove it |
| `Class "League\Flysystem\AwsS3V3\..." not found` | `league/flysystem-aws-s3-v3` missing while the disk is `s3` | Same: it's a required dependency |
| Album grids are slow and load full-size strips | Nothing is running the thumbnail jobs | Create the managed queue above; it picks up the backlog on its own |
| QR codes / invite links point at the wrong host | `APP_URL` wrong | Set `APP_URL` to the real domain |
