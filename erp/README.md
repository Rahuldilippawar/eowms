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
php artisan migrate --seed    # dev seed — accounts listed under Status below
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
- Tests at the end of Phase 1: 10 backend, 7 frontend, end-to-end on desktop and mobile; CI workflow.

### Phase 2 — done
- Schema: organizations, institutions, departments (central or per institution), permissions,
  per-organization roles with scoped permissions, role assignments limited to institutions /
  departments, append-only audit log. Dev seed with two organizations and sample users.
- Tenant isolation and scoped access enforced in the backend — see
  [`docs/ACCESS-CONTROL.md`](docs/ACCESS-CONTROL.md); endpoints in [`docs/API.md`](docs/API.md).
- Password reset (generic answers, rate limited, sessions ended on reset), organization switch,
  server messages in the UI's language.
- SPA: organization switcher, forgot / reset password pages, permission-aware menu and pages,
  read-only institution and department lists (table on desktop, cards on phones).
- Tests at the end of Phase 2: 35 backend (incl. cross-tenant attempts with manipulated ids), 18 frontend, 15 end-to-end.

Dev accounts (password `password`): `admin@example.com` (super admin), `orgadmin@`,
`principal@`, `hod@`, `staff@`, `hospital.admin@` — all `@example.com`.

### Phase 3 — done
- Create / edit / activate-deactivate for organizations (super admin), the current organization's
  settings, institutions and departments (within scope), users (account + scoped role
  assignments, set-password link instead of admin-chosen passwords) and custom roles with a
  permission × scope matrix.
- Privilege-escalation guard, self-change and last-admin protection, protected shared accounts,
  audit entries with old/new values and reasons.
- SPA: dialogs and pages for all of the above with client + server validation, confirmations,
  success toasts, phone-friendly layouts.
- Tests: 62 backend, 27 frontend, 21 end-to-end.

### Not yet (later phases)
- Dashboard KPIs, employees, students, attendance, leave (Phase 4) and the other business modules.
- Reports, deployment to Hostinger.
