# Deployment

## Docker development

```bash
docker compose up --build
docker compose exec app php artisan migrate --seed
```

The compose stack provides PHP-FPM, Nginx, MySQL, Redis, a queue worker, and a scheduler loop. Run Vite on the host for hot reload or `npm run build` before serving static assets.

## VPS production checklist

1. Set production environment variables through the host secret manager; never copy `.env` into version control.
2. Build assets with `npm ci && npm run build`, install PHP dependencies using `composer install --no-dev --classmap-authoritative`, and cache config/routes/views.
3. Point Nginx only at `public/`, enable HTTPS/HSTS, set `APP_DEBUG=false`, and use secure/same-site cookies.
4. Run `php artisan migrate --force` once per release before rolling workers.
5. Supervise queue workers and `php artisan schedule:work`; restart workers after releases.
6. Back up MySQL and private object storage, encrypt backups, test restoration, and define retention.
7. Monitor `/up`, application errors, queue failures, disk, DB capacity, certificate expiry, and backup freshness.

Inventory photos currently use the application's private local disk and are delivered through tenant-authorized API routes. `.env.example` includes empty Wasabi/S3 placeholders, but Wasabi is not active; configure and validate a private S3-compatible disk before deploying media storage off-host.
