# Dynamic Multi-Organization ERP — Phase 1 Plan

Status: **draft, waiting for owner approval** (nothing outside `docs/` has been created yet).

## 1. What exists today (inspection result)

The repository is a working, live plain-PHP + PDO + MySQL application ("E-Admin", deployed to
`amd.babashree.com` on Hostinger by `.github/workflows/deploy.yml` over FTPS).

- ~580 tracked files, ~53,500 lines of PHP, 175 MySQL tables, Marathi UI.
- Already implemented (to varying depth): organizations / campuses (institutions) / departments,
  database-driven roles + permission keys, staff and students, biometric + manual attendance,
  leave, daily work, tasks (with verify/reopen), correspondence (inward/outward numbers, workflow
  templates, fingerprint/password sign), renewals (reminders, history, attachments), hostels,
  residency, vehicles (trips, fuel, maintenance), maintenance log, tickets (requests + IT, SLA),
  assets (dead stock, IT assets, issue/return register), department finance (budget ledger,
  purchases, payments), notices, notification centre + web push, SOS, audit log, JSON/ZIP
  backup + restore, gaushala, music, meditation, events, guests, room booking, SOP.
- Not present: vendor master, quotation → PO → GRN → invoice purchase chain, a true stock
  ledger (store is only a material list), correspondence PDF with public QR verification,
  Excel/PDF exports for most reports, automated test suite, versioned JSON API.

Owner decision (this session): the new Laravel + React ERP **replaces** the current app at
`amd.babashree.com` once it reaches parity. Until cutover the current app stays live and
untouched. Existing data will be **migrated** into the new database by an import tool.

## 2. Target architecture

| Concern | Choice | Why |
|---|---|---|
| Backend | Laravel 12 (PHP 8.2+), REST JSON API under `/api/v1` | Supported LTS-style release; runs on Hostinger PHP 8.2/8.3 |
| Frontend | React 18 + Vite + TypeScript, Tailwind CSS, Radix-based components (shadcn/ui pattern), React Router, TanStack Query, React Hook Form + Zod, Recharts, react-i18next (Marathi + English) | Static build — no Node server needed on Hostinger |
| Auth | Laravel Sanctum **SPA cookie auth**, same origin | HttpOnly session cookie + CSRF; no tokens in JS; no CORS needed |
| Database | MySQL/MariaDB, InnoDB, utf8mb4 | Same server as today |
| IDs | `BIGINT UNSIGNED` auto-increment PKs everywhere; separate random 26-char ULID `public_token` only where a record is exposed publicly (QR verification) | One identifier type internally; unguessable public references |
| Money | `DECIMAL(14,2)`; quantities `DECIMAL(14,3)`; brick/math in PHP for arithmetic | No float rounding |
| Tenancy | `organization_id` on every business table + `institution_id` / `department_id` where relevant; a `TenantScope` global scope + Policies + scoped query builders; super admin may switch organization | Defence in depth |
| Permissions | `roles`, `permissions` (module.action), `role_permission` with **scope** (global / organization / institutions / departments / own / assigned), `user_role_assignments` (user + role + organization + optional institution/department list) | Database-driven, no hard-coded role checks |
| Files | `storage/app/private`, served only through authorized controllers (signed, short-lived URLs for previews) | Nothing private under the web root |
| PDF | mPDF (Devanagari shaping works; dompdf cannot shape Marathi) | Marathi correspondence PDFs |
| Excel | PhpSpreadsheet / OpenSpout via maatwebsite/excel | Streaming exports |
| QR | chillerlan/php-qrcode | Pure PHP |
| Queue / scheduler | `database` queue driver; one Hostinger cron `* * * * * php artisan schedule:run`; scheduler runs `queue:work --stop-when-empty --max-time=50` | Shared hosting has no supervisor/websockets |
| Audit | `audit_logs` table written by a service + model observers; append-only (no update/delete routes; DB user for app has no reason to delete) | Immutable history |
| Tests | PHPUnit/Pest feature + unit; Vitest + Testing Library; Playwright E2E | |

### Repository layout (new code only; current app untouched until cutover)

```
erp/
  backend/            Laravel app (app/, routes/api.php, database/migrations, tests/)
  frontend/           React + Vite app (src/, tests/), builds into backend/public/app
  docs/               API reference, deployment guide, data-migration notes
.github/workflows/
  erp-ci.yml          tests + builds on every PR (does not deploy)
  erp-deploy.yml      deploy to staging, later production (manual trigger)
```

### Hostinger layout

```
/home/<user>/erp-app/            Laravel code, vendor/, storage/, .env  (NOT web-reachable)
/home/<user>/domains/<staging-subdomain>/public_html/
                                 contents of backend/public (index.php + built React assets)
```

- During development the ERP deploys to a **staging subdomain** (e.g. `erp.babashree.com`);
  cutover later points `amd.babashree.com` at it.
- Deploy works **without SSH**: GitHub Actions runs `composer install --no-dev`, builds React,
  and uploads over the existing FTPS secrets. Migrations then run through a one-time,
  re-authenticated "System → Database migrate" screen (super admin only). If SSH is enabled
  later, the workflow switches to `php artisan migrate --force` over SSH.
- `.env` is created by hand on the server once (never committed); `APP_DEBUG=false`.

## 3. Phases (each ends in its own PR, tests run, then waits for approval)

1. **Setup & architecture** — scaffold `erp/backend` (Laravel, Sanctum, Pint, PHPUnit) and
   `erp/frontend` (Vite, TS, Tailwind, ESLint, Vitest), app shell (login page, layout, sidebar,
   top bar, i18n), CI workflow, coding conventions, this document turned into `erp/docs/`.
2. **Database foundation, auth, multi-tenant access** — organizations, institutions,
   departments, users, roles/permissions/scopes schema; login/logout, password reset, rate
   limiting, activation; TenantScope + policies; cross-tenant tests with manipulated ids.
3. **Organizations, institutions, departments, users, roles UI** — full CRUD + role editor.
4. **Dashboard, employees, students, attendance, leave.**
5. **Daily work, tasks, notifications, performance reports.**
6. **Correspondence, approval engine, signature events, PDF + QR verification.**
7. **Renewals and financial reports.**
8. **Hostel, transport, fuel, maintenance tickets.**
9. **Assets, inventory ledger, purchasing, vendors.**
10. **Global search, reports/exports, audit log viewer, backup / reset / restore.**
11. **Security review, test completion, docs, staging deploy verification, old-data importer,
    cutover plan.**

Modules that exist today but are not in the master prompt (gaushala, music, meditation,
events, guests, room booking, SOP, residency, SOS, biometric devices) are **not dropped**:
they stay in the old app until cutover and get their own phase(s) after phase 11, or the owner
decides which to retire.

## 4. Risks and constraints

- **Size**: this is a multi-week rewrite. Each phase is several thousand lines; work will span
  many sessions. Usage limits have already paused work in this project, so phases are kept
  small and always committed in a working state.
- **Hostinger Premium**: needs PHP ≥ 8.2 selected for the staging subdomain, and cron access
  (available). No Node server, no websockets, no queue supervisor — designed around this.
- **Parity before cutover**: users keep the old app until every module they use exists in the
  ERP and the data import has been rehearsed on a copy of production data.
- **Data import**: old schema has organization-level scoping but several legacy shapes
  (e.g. checkout / IT requests now merged); the importer maps them and reports anything it
  cannot map instead of guessing.

## 5. What the owner needs to provide (when we reach the relevant phase)

1. A staging subdomain in hPanel (e.g. `erp.babashree.com`) with PHP 8.2 or 8.3 selected.
2. A new empty MySQL database + user for the ERP (credentials go only into the server `.env`).
3. Optional: SSH access enabled (makes migrations and rollbacks simpler).
