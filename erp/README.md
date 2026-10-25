# E-Admin ERP (Laravel + React)

The new ERP that will replace the current PHP app (repository root) once it reaches parity.
Plan and phases: [`docs/erp/IMPLEMENTATION-PLAN.md`](../docs/erp/IMPLEMENTATION-PLAN.md).

```
erp/
  backend/    Laravel 12 REST API (/api/v1) + serves the built SPA
  frontend/   React 19 + Vite + TypeScript + Tailwind 4 (builds into backend/public/app)
```

## Local development

Requirements: PHP 8.2+ (8.3 used in CI), Composer 2, Node 22, MySQL 8 / MariaDB 10.6+.

```bash
# backend
cd erp/backend
composer install
cp .env.example .env          # set DB_* for your local database
php artisan key:generate
php artisan migrate --seed    # dev seed: admin@example.com / password
php artisan serve             # http://127.0.0.1:8000

# frontend (second terminal)
cd erp/frontend
npm install
npm run dev                   # http://localhost:5173 (proxies /api and /sanctum to :8000)
```

`npm run build` writes the production bundle to `backend/public/app`; Laravel then serves the
app at `http://127.0.0.1:8000/` directly.

## Tests

| What | Command |
|---|---|
| Backend feature/unit (uses DB `erp_test`) | `cd erp/backend && php artisan test` |
| Backend code style | `cd erp/backend && vendor/bin/pint --test` |
| Frontend lint / types / unit | `cd erp/frontend && npm run lint && npm run typecheck && npm test` |
| End-to-end (needs `npm run build` + a migrated, seeded DB) | `cd erp/frontend && npx playwright test` |

CI runs all of these on every pull request touching `erp/` (`.github/workflows/erp-ci.yml`).

## Conventions

- API routes live under `/api/v1`, return JSON only, and never include stack traces when
  `APP_DEBUG=false`.
- Auth is Laravel Sanctum **SPA cookie** auth on the same origin: the browser holds an
  HttpOnly session cookie, axios sends `X-XSRF-TOKEN`; no tokens in JavaScript or storage.
- Every user-facing string is translated (`frontend/src/i18n/locales/{mr,en}.json`,
  `backend/lang/{mr,en}`); Marathi is the default.
- Sidebar entries are added only for modules that actually work.
- Secrets (`.env`, DB passwords, keys) are never committed.

## Status

### Phase 1 — done
- Laravel 12 API skeleton: `/api/v1/health`, `/auth/login` (rate limited), `/auth/me`,
  `/auth/logout`; inactive users rejected at login and signed out mid-session; JSON errors;
  same-origin CORS only.
- React app shell: login page with validation, protected routes, responsive layout (desktop
  sidebar, mobile drawer), user menu with sign-out, Marathi/English switch, dashboard
  placeholder that states plainly which modules are not built yet.
- Tests: 10 backend feature tests, 7 frontend component tests, end-to-end tests on desktop and
  mobile viewports; CI workflow.

### Not yet (later phases)
- Organizations, institutions, departments, roles/permissions and tenant scoping (Phase 2–3).
- Password reset e-mail, all business modules, reports, deployment to Hostinger.
