# Deploying to Laravel Cloud

The app is portable by construction (standard Laravel, all file writes through the `Storage`
facade, plain Postgres, no serverless code). Deployment is almost entirely **platform config** —
there are no code changes needed to run on Laravel Cloud.

## Environment variables (Laravel Cloud dashboard)

```
APP_NAME=Photobooth
APP_ENV=production
APP_DEBUG=false            # flip to true briefly if you need to read a real error
APP_KEY=                   # generate one (Cloud can, or `php artisan key:generate --show`)
APP_URL=https://your-domain   # important: QR codes + invite links use this / the request host

# Database — attach a Postgres database in Cloud; it injects these. Just ensure:
DB_CONNECTION=pgsql

# Sessions, cache (throttle state), queue — DB-backed so they're shared across containers.
# The `sessions` and `cache` tables already exist in the migrations.
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

# Object storage — attach Cloud object storage; it injects the AWS_* vars. Then:
FILESYSTEM_DISK=s3
# (AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY / AWS_DEFAULT_REGION / AWS_BUCKET /
#  AWS_ENDPOINT / AWS_USE_PATH_STYLE_ENDPOINT are provided by the attached bucket.)
```

Photos and logos are written to the default disk and served **through the app**
(`Storage::response()`), so a **private** bucket is correct — nothing needs to be publicly
readable. With `FILESYSTEM_DISK=local` (the default) uploads land on the container's ephemeral
disk and vanish on redeploy, so `FILESYSTEM_DISK=s3` is the one that actually matters for keeping
guests' photos.

## Build + deploy commands

Build (Cloud auto-detects PHP + Node):

```
composer install --no-dev --optimize-autoloader
npm ci && npm run build          # MUST run — produces public/build/manifest.json for @vite
```

Deploy (run on every release, after build):

```
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

> **`config:cache` captures env at cache time.** If you change env vars (e.g. attach the database
> or set `DB_CONNECTION`) you must **redeploy** (or `php artisan config:clear`) so the cache picks
> them up — otherwise the app keeps running with the stale/default values.

Do **not** run `php artisan db:seed` in production — the demo seed is guarded to `local` and will
no-op, but there's no reason to invoke it.

## The queue worker (album thumbnails)

Every upload dispatches a `GenerateThumbnail` job onto the `database` queue: it writes a
480px-wide derivative beside the original and records it on the photo row. The album grids ask
for that derivative instead of the full file. A composed strip is a fixed size whatever the phone
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

**Laravel Cloud needs a worker to run them.** Add a **Worker** process on the environment:

```
php artisan queue:work --tries=3 --timeout=60
```

It needs the same env as the web process (it reads and writes object storage). Nothing breaks
without a worker — uploads still succeed and the grids serve full-size originals — but every
guest pays for that in bandwidth, so treat it as required.

> The `jobs` and `failed_jobs` tables already exist in the migrations, so there's nothing to
> create. Check `failed_jobs` if thumbnails stop appearing.

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
| Uploads work but photos disappear after a redeploy | Writing to ephemeral local disk | `FILESYSTEM_DISK=s3` + attach object storage |
| Album grids are slow and load full-size strips | No queue worker, so no thumbnails were generated | Add the worker process above; it picks up the backlog on its own |
| QR codes / invite links point at the wrong host | `APP_URL` wrong | Set `APP_URL` to the real domain |
