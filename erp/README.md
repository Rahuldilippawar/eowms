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

### Phase 4 — done
- Dashboard with real, scoped KPIs (employees, present / on leave / absent today, students, pending
  approvals) and charts (attendance by day, employees by department and institution) with period,
  custom-range, institution and department filters; each tile appears only with its permission.
- Employees (primary + additional departments, reporting officer, optional login link) and students.
- Attendance: server-time check-in / check-out, late and half-day rules, overnight shifts, marking by
  others within scope, correction requests and approval, summary report with CSV export.
- Leave: types per organization, apply with private attachment, overlap check, approval by the
  reporting officer or a scoped approver, approved days written into attendance, cancel.
- Rules: [`docs/ATTENDANCE.md`](docs/ATTENDANCE.md). New seed account: `teacher@example.com`
  (reports to `hod@`, who reports to `principal@`).
- Tests: 86 backend, 33 frontend, 25 end-to-end.

### Phase 5 — done
- Daily work log with an edit window, 24-hour cap, task link, scoped review and CSV export.
- Tasks with assign → start → mark done → verify / send back / cancel, a timeline, comments,
  reassignment, overdue flags and per-task permissions (reporting officer, scoped roles).
- In-app notifications (bell + list, in the reader's language) for tasks, leave, corrections and work
  reviews; hourly due-tomorrow / overdue reminders (`erp:task-reminders`, needs the scheduler cron).
- Performance report per employee (attendance + work log + tasks) with CSV, and "my figures".
- Dashboard tiles for my open tasks, tasks waiting for my check and overdue tasks.
- Rules: [`docs/WORK-AND-TASKS.md`](docs/WORK-AND-TASKS.md). Seed: a sample task from `hod@` to `teacher@`.
- Tests: 102 backend, 39 frontend, 27 end-to-end.

### Phase 5A-1 — done (old-app parity, staff)
- Full HR profile with the old app's fields, private data (encrypted Aadhaar / bank, masked, audited
  reveal), photo, documents, family, achievements; employees update their own contact details.
- Staff CSV import with a full check before an all-or-nothing import; ID cards with a QR code and a
  public verification page; moving many staff to another department.
- Organization-defined fields on employee, student and daily-work forms; modules on / off per
  organization; own password change and forced change for flagged accounts.
- Rules: [`docs/PEOPLE.md`](docs/PEOPLE.md). Old-app coverage: [`../docs/erp/PARITY.md`](../docs/erp/PARITY.md).
- Tests: 114 backend, 44 frontend, 29 end-to-end.

### Phase 5A-2a — done (old-app parity, attendance and leave)
- Holiday calendar (+ CSV import), organization attendance rules with weekly offs, per-employee shifts.
- Leave counts working days only and is checked against yearly balances (with allocations per person).
- Month grid, in / out register, printable attendance certificate, salary (pay-days) report.
- ZKTeco machines over the push (ADMS) protocol, machine PINs, unknown-PIN list.
- Daily jobs: mark absent, late / absence pattern alerts, birthday and work-anniversary wishes.
- Tests: 120 backend, 48 frontend, 31 end-to-end.

### Phase 5A-2b — done (old-app parity, students and daily work)
- Student roll no., admission no., year of study, academic year, mobile and email; student CSV import
  and export.
- Daily work: team members, requested by, estimated / actual cost, before / after photos, and import
  of the old app's work records. Tasks record the money spent.
- Shared CSV import screen (check first, then all rows or none) for staff, students and work.
- Tests: 124 backend, 53 frontend, 35 end-to-end.

### Phase 6 — done (correspondence and approvals)
- Files with file / outward / inward numbers, a movement register, remarks, attachments and dispatch.
- Generic approval workflows (department heads or named people), sending back one step, reassign.
- Sign and close with a password; a SHA-256 seal; printable page with a QR code to a public check
  that shows whether the file changed after signing. Inward / outward registers with CSV.
- Daily reminders for files that do not move. Rules: [`docs/CORRESPONDENCE.md`](docs/CORRESPONDENCE.md).
- Tests: 130 backend, 59 frontend, 37 end-to-end.

### Phase 7a — done (department finance)
- Department budgets per financial year, one spend ledger (purchases, approved budget letters).
- Material purchases with GST / transport, payments in parts, over-budget warnings, CSV.
- Rules: [`docs/FINANCE.md`](docs/FINANCE.md).
- Tests: 133 backend, 62 frontend, 39 end-to-end.

### Phase 7b — done (renewals)
- Renewal items with status from the expiry date, renew with history, payments per cycle or per
  month (charged to the department budget), private documents, calendar, cost analysis, CSV import
  and export, and the 90 → 0 day reminder schedule. Rules: [`docs/RENEWALS.md`](docs/RENEWALS.md).
- Tests: 137 backend, 66 frontend, 41 end-to-end.

### Not yet (later phases)
- Hostel, residency, transport, maintenance, requests (Phase 8) and the other business modules (see
  [`../docs/erp/PARITY.md`](../docs/erp/PARITY.md)); email / push delivery of notifications.
- Reports, deployment to Hostinger.
