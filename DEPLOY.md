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

Do **not** run `php artisan db:seed` in production — the demo seed is guarded to `local` and will
no-op, but there's no reason to invoke it.

## First deploy: create your admin

Registration can't grant admin (by design — no self-escalation), and the demo seed is local-only,
so production starts with no admin. After the app is up:

1. Register yourself through the UI (`/register`).
2. In Cloud's command runner (or SSH): `php artisan photobooth:make-admin you@example.com`

You're now an admin and can see/manage every event.

## Troubleshooting the usual first-deploy failures

| Symptom | Cause | Fix |
|---|---|---|
| Booth / create / owner / dashboard pages 500, but `/`, `/login` load fine | Vite manifest missing — `npm run build` didn't run | Ensure the build step runs `npm run build` and produced `public/build/manifest.json` |
| 500 on **every** page | `APP_KEY` unset, or migrations haven't run | Set `APP_KEY`; confirm `php artisan migrate --force` is in the deploy commands (temporarily set `APP_DEBUG=true` to read the exact error) |
| "could not find driver" / "table not found" | DB not attached / `DB_CONNECTION` wrong / migrations not run | Attach Postgres, `DB_CONNECTION=pgsql`, run `migrate --force` |
| Uploads work but photos disappear after a redeploy | Writing to ephemeral local disk | `FILESYSTEM_DISK=s3` + attach object storage |
| QR codes / invite links point at the wrong host | `APP_URL` wrong | Set `APP_URL` to the real domain |
