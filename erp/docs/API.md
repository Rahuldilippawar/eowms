# API reference (v1)

Base path: `/api/v1`. JSON only. Authentication: Laravel Sanctum SPA session — call
`GET /sanctum/csrf-cookie` first, then send requests with the session cookie and the
`X-XSRF-TOKEN` header (axios does this automatically). Send `Accept-Language: mr|en` to get
messages in that language.

Errors: `401` not signed in · `403` permission not held · `404` not found **or outside your
scope** · `422` validation (`{message, errors: {field: [..]}}`) · `429` rate limited.
List endpoints are paginated: `{data: [...], links: {...}, meta: {current_page, last_page, per_page, total, from, to}}`.

## Public

| Method | Path | Body / query | Notes |
|---|---|---|---|
| GET | `/health` | — | `{status, database}`; 503 if the DB is down |
| POST | `/auth/login` | `email, password, remember?` | 5/min per email+IP, 20/min per IP. One generic error for unknown email, wrong password or inactive account. Returns the current user (see `/auth/me`). |
| POST | `/auth/forgot-password` | `email` | Always the same answer. 3/min per email+IP. Inactive users get no mail. |
| POST | `/auth/reset-password` | `token, email, password, password_confirmation` | Min 8 chars with letters and numbers. Ends all of the user's sessions. |

## Signed in

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/auth/me` | — | `{id, name, email, is_super_admin, last_login_at, current_organization, organizations[], permissions[]}` |
| POST | `/auth/switch-organization` | membership | Body `organization_id`. 403 if the user has no role there (super admins: any active organization). |
| POST | `/auth/logout` | — | 204; session invalidated |
| GET | `/organizations` | — | Super admin: all; others: the current organization only |
| POST | `/organizations` | super admin | `name, short_name?, code, address?, phone?, email?, website?, financial_year_start_month`. Creates the built-in roles. |
| GET / PUT | `/organizations/{id}` | `organizations.view` / `organizations.edit` (organization scope) | Others' organizations are 404 |
| POST | `/organizations/{id}/active` | super admin | `is_active, reason?` |
| GET | `/institutions` | `institutions.view` | Query `search, type, is_active, per_page (≤100), page`. Scoped. |
| POST | `/institutions` | `institutions.create` (organization scope) | `type, name, code, address?, phone?, email?, head_user_id?`; code unique per organization |
| GET / PUT | `/institutions/{id}` | `institutions.view` / `institutions.edit` | 404 outside organization or scope |
| POST | `/institutions/{id}/active` | `institutions.activate` | `is_active, reason?` |
| GET | `/departments` | `departments.view` | Query `search, institution_id, central, is_active, per_page, page`. Scoped. |
| POST | `/departments` | `departments.create` | `institution_id? (null = central), name, code, category?, head_user_id?`. Organization scope: anywhere; institution scope: only its institutions; code unique per (organization, institution). |
| GET / PUT | `/departments/{id}` | `departments.view` / `departments.edit` | Moving to another institution follows the create rule |
| POST | `/departments/{id}/active` | `departments.activate` | `is_active, reason?` |
| GET | `/users` | `users.view` | People holding a role here, limited to the caller's scope. Query `search, is_active, per_page, page` |
| POST | `/users` | `users.create` + `roles.assign` | `name, email, role_id, institution_ids?, department_ids?`. Sends a set-password link; no password is chosen by the admin. |
| GET / PUT | `/users/{id}` | `users.view` / `users.edit` | `name, email`. Accounts shared with another organization and super admins: super admin only. |
| POST | `/users/{id}/active` | `users.activate` | Not on oneself; deactivation ends the user's sessions |
| POST | `/users/{id}/password-reset` | `users.reset_password` | Emails a reset link |
| POST | `/users/{id}/role-assignments` | `roles.assign` | `role_id, institution_ids?, department_ids?`; not on oneself |
| DELETE | `/role-assignments/{id}` | `roles.assign` | Not on oneself; never removes the organization's last admin |
| GET | `/permissions` | `roles.view` | Catalogue by module, with the caller's own scope per key |
| GET / POST | `/roles` | `roles.view` / `roles.create` | `name, description?, permissions: [{key, scope}]` |
| GET / PUT / DELETE | `/roles/{id}` | `roles.view` / `roles.edit` / `roles.delete` | Built-in roles cannot be deleted, roles in use cannot be deleted, a role you hold cannot be edited by you, Organization Admin permissions are fixed |

**Privilege escalation guard** (`App\Support\Access\GrantGuard`): when creating or editing a role, or
assigning / removing one, every permission involved must be held by the actor at a scope at
least as wide, and the listed institutions / departments must be inside the actor's own reach.
Violations are `422`. Super admins are exempt. `is_super_admin` can never be set through the API.

Every security-relevant action is written to `audit_logs` (append-only): login, failed login,
logout, organization switch, password-reset request, password reset, and every create / update /
activate / deactivate / role change, with old and new values of the changed fields and the optional
reason.

## Phase 4 — dashboard, people, attendance, leave

Rules behind these endpoints: [`ATTENDANCE.md`](ATTENDANCE.md).

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/dashboard` | any signed-in user | Query `period (today/week/month/year/custom), from?, to? (custom, ≤ 1 year), institution_id?, department_id?`. Each KPI / chart is `null` without its permission; counts are scoped. |
| GET | `/me/employee` | — | The caller's employee record in the current organization, or `null` |
| GET | `/me/attendance` | — | Query `month=YYYY-MM` |
| POST | `/me/attendance/check-in`, `/check-out` | — | Server time; 10 per minute |
| GET / POST | `/me/attendance/corrections` | — | `work_date, requested_status, requested_check_in?, requested_check_out?, reason` |
| GET / POST | `/me/leave` | — | multipart `leave_type_id, start_date, end_date, reason, attachment?` |
| POST | `/me/leave/{id}/cancel` | — | Own request, while still pending |
| GET | `/leave-types/active` | — | Active types for the apply form |
| GET | `/leave-requests/{id}/attachment` | owner, decider or `leave.view` in scope | Private file download |
| GET / POST | `/employees` | `employees.view` / `employees.create` | `employee_code, name, designation?, phone?, email?, department_id, additional_department_ids?, reporting_officer_id?, user_id?, joining_date?`. Institution follows the department; department must be in the caller's scope; `user_id` must hold a role here. |
| GET / PUT | `/employees/{id}` | `employees.view` / `employees.edit` | |
| POST | `/employees/{id}/active` | `employees.activate` | `is_active, reason?` |
| GET / POST | `/students` | `students.view` / `students.create` | `student_code, name, institution_id, department_id? (same institution), class_name?, section?, gender?, date_of_birth?, admission_date?, guardian_name?, guardian_phone?` |
| GET / PUT | `/students/{id}` | `students.view` / `students.edit` | |
| POST | `/students/{id}/active` | `students.activate` | |
| GET | `/attendance` | `attendance.view` | Query `date, search?, page`: employees in scope with that day's record |
| PUT | `/attendance` | `attendance.create` or `attendance.edit` | `employee_id, date (not future), status, check_in?, check_out?, remarks?` |
| GET | `/attendance/summary` | `attendance.view` (+ `attendance.export` for CSV) | Query `from, to, group_by (employee/department/institution), format=csv?`. CSV is UTF-8 with BOM, formula-escaped, and the export is audited. |
| GET | `/attendance/corrections` | `attendance.approve` | Pending corrections in scope, excluding the caller's own |
| POST | `/attendance/corrections/{id}/decide` | `attendance.approve` | `approve, remarks?` |
| GET | `/leave-approvals` | — | Requests the caller may decide (reporting officer or `leave.approve` scope) |
| POST | `/leave-requests/{id}/decide` | as above | `approve, remarks?`; 404 if the caller may not decide it |
| GET | `/leave-requests` | `leave.view` | Query `status?, employee_id?, from?, to?` |
| GET / POST / PUT | `/leave-types`, `/leave-types/{id}` | `leave.manage_types` (organization scope) | `code, name, days_per_year, is_paid, is_active` |

## Phase 5 — daily work, tasks, notifications, performance

Rules behind these endpoints: [`WORK-AND-TASKS.md`](WORK-AND-TASKS.md).

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/me/work` | own employee record | Query `from, to` (default last 7 days). `meta.edit_days`, `meta.today`; each entry has `can_edit` |
| POST / PUT / DELETE | `/me/work`, `/me/work/{id}` | own entry | `work_date, title, description?, hours? (0.25–24, step 0.25), status (completed/in_progress/pending), task_id? (own task)` |
| GET | `/me/tasks/open` | — | The caller's open tasks, for linking work |
| GET | `/work` | `daily_work.view` | Query `from, to, employee_id?, department_id?, institution_id?, reviewed?, search?, page` |
| GET | `/work/export` | `daily_work.export` | Same filters, CSV |
| POST | `/work/{id}/review` | `daily_work.review` | `remarks?`; not one's own work |
| GET | `/tasks` | — (only tasks the caller may see) | Query `view (mine/assigned/all), status (… or active), overdue?, search?, page` |
| GET | `/tasks/assignable` | — | Employees the caller may assign to |
| POST | `/tasks` | reporting officer or `tasks.create` in scope | `title, description?, priority (low/normal/high/urgent), due_date (not past), assignee_employee_id` |
| GET / PUT | `/tasks/{id}` | visible / manager | Detail includes `actions` (what the caller may do) and `updates` (timeline) |
| POST | `/tasks/{id}/start`, `/submit` | assignee | `note?` |
| POST | `/tasks/{id}/complete`, `/reopen`, `/cancel` | manager | `note` (required for reopen and cancel) |
| POST | `/tasks/{id}/comments` | assignee or manager | `body` |
| GET | `/notifications` | own | Query `unread?, per_page (≤50), page`; response also has `unread` |
| GET | `/notifications/unread-count` | own | |
| POST | `/notifications/{id}/read`, `/notifications/read-all` | own | Return the new unread count |
| GET | `/me/performance` | own employee record | Query `from, to` |
| GET | `/performance` | `performance.view` (+ `performance.export` for `format=csv`) | Query `from, to, department_id?, institution_id?, search?, page` |

`GET /dashboard` adds `my_open_tasks`, `tasks_to_verify` and `overdue_tasks` (needs `tasks.view`).

## Phase 5A-1 — staff profiles, import, ID cards, custom fields, modules

Rules: [`PEOPLE.md`](PEOPLE.md).

| Method | Path | Permission | Notes |
|---|---|---|---|
| POST | `/auth/change-password` | signed in | `current_password, password, password_confirmation`; clears a forced change; 10/min |
| GET / PUT | `/me/profile` | own employee record | PUT: `whatsapp, local_address, permanent_address, blood_group, emergency_contact_*` only |
| GET / PUT | `/employees/{id}/profile` | view: in scope or self · edit: `employees.edit` | HR fields + `custom`; response has `can`, `documents`, `family_members`, `achievements`, and `private` (masked) when allowed |
| PUT | `/employees/{id}/private` | `employees.sensitive` in scope | Aadhaar / bank are replaced only when sent |
| POST | `/employees/{id}/private/reveal` | `employees.sensitive` in scope or self | Full numbers; audited; 20/min |
| GET / POST | `/employees/{id}/photo` | view / edit or self | jpg, png, webp ≤ 2 MB, ≥ 100×100 |
| POST | `/employees/{id}/documents` | edit or self | multipart `document_type, title?, file` (pdf/jpg/png ≤ 5 MB) |
| GET / DELETE | `/employee-documents/{id}` | view / edit or own upload | Download is audited |
| POST / PUT / DELETE | `/employees/{id}/family[/{member}]` | edit or self | `name, relation, date_of_birth?, phone?, lives_on_campus?` |
| POST / DELETE | `/employees/{id}/achievements[/{id}]` | `employees.edit` | `title, description?, achieved_on?` |
| GET | `/employees/import/template` | `employees.create` | CSV |
| POST | `/employees/import/preview`, `/employees/import` | `employees.create` | multipart `file`; import is all or nothing (422 with `rows` when any row fails) |
| POST | `/employees/id-cards` | `employees.view` | `ids[]` or `department_id` |
| POST | `/employees/bulk-move` | `employees.edit` | `employee_ids[], department_id, reason?` |
| GET | `/public/employees/{token}`, `/public/employees/{token}/photo` | public, 30/min | ID-card check |
| GET | `/custom-fields/active?entity=` | signed in | Fields of one form |
| GET / POST / PUT | `/custom-fields[/{id}]` | `organizations.edit` (organization scope) | `entity, key, label, type, options?, is_required, sort_order, is_active` |
| GET / PUT | `/organization/modules` | PUT: `organizations.edit` (organization scope) | `disabled: []` |

`/auth/me` now includes `disabled_modules` and `must_change_password`. Student and work-entry forms
accept `custom` (their organization-defined fields).

## Phase 5A-2a — holidays, shifts, leave balances, attendance reports, biometric

Rules: [`ATTENDANCE.md`](ATTENDANCE.md).

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/holidays?year&institution_id?` | signed in | |
| POST / PUT / DELETE | `/holidays[/{id}]` | `attendance.manage` (organization-wide: organization scope) | `holiday_date, name, institution_id?` |
| GET / POST | `/holidays/import/template`, `/holidays/import` | `attendance.manage` | CSV; all rows or none |
| GET / PUT | `/organization/attendance-rules` | PUT: `organizations.edit` (organization scope) | `timezone, work_start, work_end, late_grace_minutes, half_day_below_hours, max_shift_hours, weekly_offs[], daily_work_edit_days` |
| GET / PUT | `/employees/{id}/attendance-settings` | view: in scope or self · PUT: `attendance.manage` in scope | shift fields (empty = organization rule) + `biometric_pin`; response has `effective` |
| GET | `/me/leave/balances?year` | own employee record | |
| GET | `/leave-balances?year&search&page` | `leave.view` | One row per employee with a balance per type; `can_allocate` |
| PUT | `/leave-balances` | `leave.balances` in scope | `employee_id, leave_type_id, year, days` |
| GET | `/attendance/grid?month&search&page&format=csv?` | `attendance.view` (+ export for CSV) | |
| GET | `/attendance/movement?from&to&employee_id?&page&format=csv?` | `attendance.view` (+ export) | ≤ 92 days |
| GET | `/attendance/certificate/{employee}?from&to`, `/me/attendance/certificate` | in scope or self | ≤ 1 year; audited |
| GET | `/reports/salary?month&format=csv?` | `employees.sensitive` + `attendance.view` | Audited |
| GET / POST / PUT | `/biometric-devices[/{id}]` | `attendance.manage` (organization scope) | `name, serial_number, institution_id?, is_active` |
| GET | `/biometric-punches?result=unknown_pin` | `attendance.manage` (organization scope) | Latest 200 |
| GET, POST | `/iclock/cdata`, `/iclock/getrequest`, `/iclock/devicecmd` (no `/api/v1`) | registered machine serial | ZKTeco ADMS; plain-text replies |

Leave applications now count working days and are checked against the balance.

## Phase 5A-2b — students, daily-work team / cost / photos / import, task cost

Rules: [`PEOPLE.md`](PEOPLE.md) (students), [`WORK-AND-TASKS.md`](WORK-AND-TASKS.md).

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET / POST / PUT | `/students[/{id}]` | as before | New fields `roll_no, admission_no, year_of_study, academic_year, mobile, email`; filters `academic_year, year_of_study` |
| GET | `/students/export?search&institution_id&class_name&academic_year&year_of_study&is_active` | `students.export` | CSV, audited |
| GET / POST | `/students/import/template`, `/students/import/preview`, `/students/import` | `students.create` | CSV; preview writes nothing; all rows or none |
| GET | `/me/work/colleagues?search` | own employee record | Up to 20 active colleagues: id, name, code, department |
| POST / PUT | `/me/work[/{id}]` | own entry | New fields `requested_by, estimated_cost, actual_cost, team_employee_ids[]` |
| POST / DELETE | `/me/work/{id}/photos/{before\|after}` | own editable entry | `photo`: JPEG / PNG / WebP ≤ 5 MB |
| GET | `/work/{id}/photos/{before\|after}` | owner, team mate or `daily_work.view` in scope | Private file |
| GET / POST | `/work/import/template`, `/work/import/preview`, `/work/import` | `daily_work.import` | Old records; all rows or none |
| POST | `/tasks/{id}/submit`, `/tasks/{id}/complete` | as before | Optional `cost_incurred` |

Work entries now return `requested_by, estimated_cost, actual_cost, team[], has_before_photo,
has_after_photo, is_imported`; tasks return `cost_incurred`.

## Phase 6 — correspondence, approval workflows, signatures

Rules: [`CORRESPONDENCE.md`](CORRESPONDENCE.md). All under the `letters` module switch.

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/letters?box=with_me\|created\|all&status&letter_category_id&search&page` | signed in (sees what they may) | `meta.with_me` = files waiting with the caller |
| GET | `/letters/options`, `/letters/recipients?search` | signed in | Active categories and workflows; colleagues to send to |
| POST / PUT | `/letters[/{id}]` | creator (PUT: drafts only) | `subject, description, reference_no, letter_category_id, priority, approval_workflow_id, is_budget_expense, budget_category, requested_amount` |
| GET | `/letters/{id}` | can see the file | Detail with `actions`, `steps`, `movements`, `attachments`, `inward_numbers`, `signatures` (`intact`) |
| POST | `/letters/{id}/submit`, `/letters/{id}/forward` | creator (draft) / holder | `to_user_id` when there is no workflow, `remark` |
| POST | `/letters/{id}/return` | holder | `remark` (required) |
| POST | `/letters/{id}/remarks`, `/letters/{id}/attachments`, `/letters/{id}/dispatch` | creator or holder (dispatch: also `letters.manage`) | Attachments: `file` ≤ 10 MB |
| GET | `/letters/{id}/attachments/{attachment}` | can see the file | Audited |
| POST | `/letters/{id}/sign` | holder at the last step | `password, remark?, approved_amount?`; 10 per minute |
| POST | `/letters/{id}/reassign` | `letters.manage` reaching the file | `to_user_id, remark?` |
| GET | `/letters/register?type=outward\|inward&fy&department_id&format=csv?` | `letters.view` (+ `letters.export` for CSV) | Financial year = start year |
| GET / POST / PUT | `/letter-settings/categories[/{id}]`, `POST /letter-settings/categories/defaults` | `letters.manage` (organization scope) | |
| GET / POST / PUT | `/letter-settings/workflows[/{id}]` | `letters.manage` (organization scope) | `name, letter_category_id?, is_active, steps[{name, approver_type, department_id?, user_id?}]` |
| GET | `/public/signatures/{code}` | public, 30 per minute | Signer, organization, file / outward no., time, method, `intact` |
