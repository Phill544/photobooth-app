# Photobooth

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

## Daily development

```
composer run dev      # app server + vite + logs, all in one
php artisan test      # server tests (Pest)
npm test              # client unit tests (Vitest)
```

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
