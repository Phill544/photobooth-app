# Quikbooth

A photobooth web app for events. Guests scan a QR code, take a set of photos, watch them
compose into a photo strip on their phone, and share them to the event album. No app install.

See [PLAN.md](PLAN.md) for architecture decisions and the roadmap.

## Quickstart (fresh environment)

**Prerequisites**: PHP 8.3+, Composer 2, Node 20+.

<details>
<summary>Installing the prerequisites on Windows 11</summary>

```
winget install PHP.PHP.8.4
```

Then create `php.ini` in the PHP install directory (copy `php.ini-development`) and append:

```ini
extension_dir = "<php-install-dir>\ext"
extension=curl
extension=fileinfo
extension=mbstring
extension=openssl
extension=pdo_sqlite
extension=sqlite3
extension=zip
memory_limit = 512M
upload_max_filesize = 25M
post_max_size = 30M
```

Install Composer per [getcomposer.org/download](https://getcomposer.org/download/) and Node via
[nodejs.org](https://nodejs.org) or nvm.
</details>

Then, from the repo root:

```
composer run setup
```

That installs PHP + npm dependencies, creates `.env`, generates the app key, creates the
SQLite database, runs migrations, and builds the frontend.

Then seed a host account and a few events to look at:

```
php artisan db:seed
```

That creates the dev login (`demo@example.com` / `password`, an admin) and three events: an
empty booth to shoot into (`PARTY2`), a one-session album (`BREKKY`) and a normal small night
of twelve (`GARDEN`, closed). Every seeded photo is a real JPEG on the disk with a real
derivative beside it, because an album's cost is the number of files it asks for.

For the sizes worth measuring against — a 750-photo launch and the 4000-photo New Year's that
album pagination exists for — add:

```
php artisan db:seed --class=BigEventSeeder
```

That one writes ~9,500 files (~280MB, about 15 seconds — the images come from a small pool, so
only a dozen of them are ever drawn). It is safe to re-run: it skips any event that already has
photos.

## Daily development

```
composer run dev      # app server + vite + queue worker + logs, all in one
php artisan test      # server tests (Pest)
npm test              # client unit tests (Vitest)
```

`composer run dev` includes a queue worker, which is what generates the album's gallery
thumbnails. Without one running, uploads still work and the album falls back to serving the
full-size originals in its grids.

## Testing on a real phone

The camera API only works over HTTPS, so LAN IPs won't do. Use a tunnel:

```
npm run build
php artisan serve
cloudflared tunnel --url http://127.0.0.1:8000
```

(`winget install Cloudflare.cloudflared` if missing.) Open the printed `trycloudflare.com`
URL on the phone. Note: quick tunnels get a new random URL every run — don't print QR codes
against them. Rebuild (`npm run build`) after client changes; Vite's dev server isn't
reachable through the tunnel.
