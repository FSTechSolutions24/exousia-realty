# Exousia Realty

Exousia Realty is a multi-tenant CRM for Egyptian real estate brokerages. The current verified product slice includes company onboarding, session authentication, tenant membership and role enforcement, a live operational dashboard, lead management, Egyptian phone normalization and duplicate warnings, follow-up tasks, activity history, English/Arabic layout switching, audit records, and fictional demo data.

## Local setup

Requirements: PHP 8.2, Composer 2, Node 24 LTS, npm 11+, and MySQL 8. SQLite is used automatically by the test suite.

```bash
composer install
copy .env.example .env
php artisan key:generate
# Create the exousia_realty MySQL database, then:
php artisan migrate --seed
npm install
npm run build
php artisan serve
```

For frontend hot reload, run `npm run dev` next to `php artisan serve`. The production build is served automatically when `public/build/.vite/manifest.json` exists.

Demo users after `php artisan migrate:fresh --seed`:

- Owner: `owner@demo.exousia.test` / `password`
- Agent: `agent@demo.exousia.test` / `password`

These credentials are local fictional seed data and must never be used in production.

## Verification

```bash
php artisan test
npm run typecheck
npm run build
```

Architecture, API, security, deployment, and current milestone status are in [`docs/`](docs/IMPLEMENTATION_PLAN.md). The repository uses a modular Laravel 12 monolith with a Vue 3 SPA.
