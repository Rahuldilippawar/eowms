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

## Phase 7a — department budgets, purchases, payments

Rules: [`FINANCE.md`](FINANCE.md). All under the `finance` module switch.

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/finance/budgets?fy` | `finance.view` | One row per department in scope: allocated, spent, remaining, `by_category`, `by_source`, purchases billed / paid; `meta.can_allocate` |
| PUT | `/finance/budgets` | `finance.budget` reaching the department | `department_id, fy, allocated_amount` |
| GET | `/finance/budget-check?department_id&date&amount` | `finance.view` or `finance.manage` reaching the department | `remaining` (null = no budget), `exceeds` |
| GET | `/finance/transactions?fy&department_id&format=csv?` | `finance.view` (+ `finance.export` for CSV) | The ledger |
| GET | `/finance/purchases?fy&status=pending\|partial\|paid&search&page&format=csv?` | `finance.view` (+ export) | |
| POST / PUT | `/finance/purchases[/{id}]` | `finance.manage` reaching the department | `department_id, material_name, vendor_name, vendor_contact?, quantity, unit?, rate, transport_cost?, gst_percent?, purchase_date, remarks?` |
| GET / DELETE | `/finance/purchases/{id}` | view / manage | Delete only without payments |
| POST | `/finance/purchases/{id}/payments` | `finance.manage` | `amount ≤ due, payment_date, payment_type=advance\|partial\|final, payment_mode?, remarks?` |

Signing a budget letter (`POST /letters/{id}/sign`) now charges its approved amount to the creator's department.

## Phase 7b — renewals

Rules: [`RENEWALS.md`](RENEWALS.md). All under the `renewals` module switch.

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/renewals?status=expired\|due_soon\|active\|inactive&department_id&regulatory_body&search&page&format=csv?` | `renewals.view` (+ export for CSV) | `meta.summary`, `meta.by_regulatory_body` |
| POST / PUT | `/renewals[/{id}]` | `renewals.manage` in scope | `department_id, item_type, regulatory_body?, item_name, reference_no?, issued_date?, expiry_date, renewal_cost?, is_recurring_monthly, monthly_amount?, recurring_start_month? (Y-m), vendor_name?, vendor_contact?, responsible_user_id?` |
| GET | `/renewals/{id}` | `renewals.view` | Detail with `history`, `payments`, `months` (monthly bills), `attachments`, `cycle_paid` |
| POST | `/renewals/{id}/active` | manage | `is_active` |
| POST | `/renewals/{id}/renew` | manage | `new_expiry_date` (after the current one), `issued_date?, renewal_cost?, vendor_name?, reference_no?` |
| POST | `/renewals/{id}/payments` | manage | `amount, payment_date, payment_type, payment_mode?, for_month (Y-m, monthly bills), remarks?`; charged to the department budget |
| POST / GET | `/renewals/{id}/attachments[/{attachment}]` | manage / view | `file` ≤ 10 MB, `title?` |
| GET | `/renewals/calendar?month=Y-m`, `/renewals/costs` | `renewals.view` | |
| GET / POST | `/renewals/import/template`, `/renewals/import/preview`, `/renewals/import` | `renewals.manage` | CSV; all rows or none |

## Phase 8a — vehicles, maintenance register

Rules: [`VEHICLES.md`](VEHICLES.md). Under the `vehicles` and `maintenance` module switches.

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/vehicles?status=active\|inactive\|all&department_id&search&format=csv?` | `vehicles.view` or driver | Scoped list (+ the vehicles one drives); `next_document`, `next_service` |
| POST / PUT | `/vehicles[/{id}]` | `vehicles.manage` in scope | `department_id, vehicle_number, vehicle_type?, model?, registration_date?, chassis_no?, engine_no?, vehicle_class?, passenger_capacity?, driver_user_id?, fuel_type, current_odometer? (create only), documents: {permit?, puc?, insurance?, fitness?, road_tax?, green_tax?, mv_tax?}` |
| GET | `/vehicles/{id}` | view or driver | `documents`, `stats`, `trend`, latest `trips` / `fuel` / `services`, `pending_corrections`, `can_log`, `can_manage` |
| POST | `/vehicles/{id}/active` | manage | `is_active` (also switches the document renewal items) |
| POST | `/vehicles/{id}/trips` | driver, `vehicles.log` or manage | `trip_date, from_location, to_location, start_time?, end_time?, start_odometer, end_odometer, purpose?` |
| POST | `/vehicles/{id}/fuel` | same | `fill_date, odometer_reading, liters, cost?, fuel_station?` |
| POST | `/vehicles/{id}/services` | same | `service_date, type, description?, cost?, odometer_reading?, next_due_date?, next_due_km?, vendor?` |
| PUT / DELETE | `/vehicles/{id}/entries/{trip\|fuel\|service}/{entry}` | manage | Same fields as when logging |
| POST | `/vehicles/{id}/entries/{type}/{entry}/corrections` | can log | `request_type (edit\|delete), reason` |
| GET | `/vehicle-corrections?status=pending\|approved\|rejected\|all` | manager (scope) or own requests | With the entry as it is now and `can_review` |
| POST | `/vehicle-corrections/{id}/review` | `vehicles.manage` | `decision (approve\|reject), note?, changes?` (edit requests: the corrected fields) |
| GET | `/vehicles/{id}/report?from&to&format=csv?` | view or driver (+ export for CSV) | Totals and every entry in the period |
| GET / POST | `/vehicles/import/template`, `/vehicles/import/preview`, `/vehicles/import` | `vehicles.manage` | CSV; all rows or none |
| GET / POST / PUT | `/maintenance/buildings[/{id}]` | view / manage | `name, institution_id?` |
| GET | `/maintenance/locations?building_id&search&include_inactive` | `maintenance.view` | Counts within the viewer's scope; `meta.log_departments` |
| POST / PUT | `/maintenance/locations[/{id}]` | `maintenance.manage` | `name, location_code, building_id?, floor?, capacity?, is_residential?, is_active?` |
| GET | `/maintenance/locations/{id}` | view | History and total cost |
| GET | `/maintenance/logs?location_id&department_id&work_type&from&to&search&page&format=csv?` | view (+ export for CSV) | |
| POST | `/maintenance/logs` | `maintenance.log` in scope, register department | `location_id, department_id, item_description, work_type, work_date, cost?, remarks?` |
| GET / PUT / DELETE | `/maintenance/logs/{id}` | view / manage | No delete once paid |
| POST | `/maintenance/logs/{id}/payments` | manage | `amount, payment_date, payment_type, payment_mode?, remarks?` |
| GET / PUT | `/maintenance/departments[/{id}]` | manage | `has_maintenance_register` |
| GET | `/maintenance/report?from&to` | view | `by_department`, `by_location`, `by_work_type` |

## Phase 8b — work requests, public form, grievances

Rules: [`REQUESTS.md`](REQUESTS.md). Under the `requests` and `grievances` module switches.

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/requests?status=open\|overdue\|all\|pending…&mine&assigned=me&category&priority&department_id&search&page&format=csv?` | own / assigned / `requests.view` scope (+ export for CSV) | `meta.counts` |
| GET | `/requests/departments` | signed in | Departments that can receive a request |
| POST | `/requests` | signed in | `category, sub_category (IT), department_id (optional for IT), title, work_description, location?, priority, requester_contact?` |
| GET | `/requests/{id}` | as above | Detail with `actions`, `comments`, `seen_by`, `can_handle`, `can_assign`, `is_requester`, `can_reopen` |
| GET | `/requests/{id}/assignees`, POST `/requests/{id}/assign` | `requests.manage` over the department | `user_id` |
| POST | `/requests/{id}/status` | assignee or manager | `status (pending\|in_progress\|completed), remark (required to complete), cost_incurred?` |
| POST | `/requests/{id}/comments` | requester, assignee, manager | `comment` |
| POST | `/requests/{id}/cancel`, `/close`, `/reopen` | requester | `reason` / `rating 1–5, feedback?` / `reason` (within 7 days) |
| GET | `/requests/report?from&to&department_id` | `requests.view` | `by_status`, `by_category`, `by_department` |
| GET / POST | `/public/requests/{orgCode}` | public (honeypot `website`, 5 an hour per address) | Form data / `requester_name, requester_contact, department_id, category?, location?, work_description` |
| POST | `/public/requests/{orgCode}/track` | public | `code, contact` (both must match) |
| GET / POST | `/grievances[?status&mine]` | own / assigned / `grievances.manage` | `category?, description, is_anonymous` |
| GET / PUT | `/grievances/{id}` | as above / handler or assignee | `status, resolution (to resolve), assigned_to? (handler)` |
| POST | `/grievances/{id}/comments`, `/grievances/{id}/reopen` | sender, handler, assignee / sender | `comment` / `reason` |

## Phase 8c — hostels

Rules: [`HOSTELS.md`](HOSTELS.md). Under the `hostels` module switch. "care" = the hostel's staff, `hostels.care` or `hostels.manage` over its department.

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/hostels` | `hostels.view` scope or hostel staff | With residents, beds, out now, late returns |
| POST / PUT | `/hostels[/{id}]` | `hostels.manage` in scope | `name, department_id, gender (boys\|girls\|mixed), capacity?, is_active?` |
| GET | `/hostels/{id}` | as above | Dashboard: `staff`, `rooms`, `stats` (today's roll calls, out, fees due), `can_manage`, `can_care` |
| PUT | `/hostels/{id}/staff` | manage | `staff: [{user_id, role?}]` (replaces the list) |
| POST / PUT | `/hostels/{id}/rooms[/{room}]` | manage | `room_number, capacity, is_active?` |
| GET / POST | `/hostels/{id}/residents[?status=active\|out\|left&search&format=csv]` | care (+ export for CSV) | `name, school_name?, class_grade?, district?, gender?, date_of_birth?, admission_date?, guardian_name?, guardian_phone?` |
| GET / POST | `/hostels/residents/import/template`, `/hostels/{id}/residents/import/preview`, `/hostels/{id}/residents/import` | care | CSV; all rows or none |
| GET / POST | `/hostels/{id}/attendance?session&date` | care | Sheet / `entries: [{resident_id, status (present\|absent\|on_leave), remark?}]` |
| GET / PUT | `/hostel-residents/{id}` | care | Detail with beds, out-passes, health, incidents, fees, alumni record, last 30 days |
| POST | `/hostel-residents/{id}/allocate`, `/vacate` | care | `room_id, bed_label?` |
| POST | `/hostel-residents/{id}/out`, `/returned` | care | `leave_date, expected_return_date?, reason?, escort_name?` / `actual_return_date?` |
| PUT / POST | `/hostel-residents/{id}/health`, `/incidents` | care | Health profile / `incident_date, description, action_taken?` |
| POST | `/hostel-residents/{id}/fees`, `/hostel-fees/{fee}/payments` | care | `fee_period, amount_due, due_date?` / `amount, payment_mode, paid_on?, remarks?` |
| POST / PUT | `/hostel-residents/{id}/depart`, `/alumnus` | care | `departure_date, departure_reason?, current_status?, contact_number?, notes?` |

## Phase 8d — staff housing

Rules: [`RESIDENCY.md`](RESIDENCY.md). Under the `residency` module switch.

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/my/residency` | signed in | `home` (room, building, family), `bills` (own shares), `requests`, `buildings` |
| POST | `/my/residency/requests`, `/my/residency/requests/{id}/cancel` | signed in | `preferred_building_id?, family_size, reason?`; one open request, not when housed |
| GET | `/residency/rooms[?building_id&search&vacant]` | `residency.view` | Residential rooms with occupants count and staff names; `meta.can_manage`, `meta.buildings` |
| GET | `/residency/rooms/{id}` | `residency.view` | Occupants, items, inspections, bills with shares, guests, room changes |
| POST | `/residency/rooms/{id}/occupants` | `residency.manage` | `occupant_type (staff\|family), user_id (staff), family_of_id + name (family), relation?, age?, moved_in_date?` |
| POST | `/residency/occupants/{id}/vacate`, `/transfer`, `/link` | `residency.manage` | `vacated_date?, remark?` / `to_location_id, transfer_date?, reason?` / `user_id` |
| POST / DELETE | `/residency/rooms/{id}/items`, `/residency/items/{id}` | `residency.manage` | `item_name, ownership (institution\|personal), quantity, remarks?` |
| POST | `/residency/rooms/{id}/inspections` | `residency.manage` | `inspection_date, condition_rating (excellent\|good\|fair\|poor), remarks?` |
| POST | `/residency/rooms/{id}/bills`, `/residency/bill-shares/{id}/paid` | `residency.manage` | `billing_month (YYYY-MM), room_rent?, electricity_bill?, water_bill?` / `paid` |
| GET | `/residency/bills?month` | `residency.view` | A month across rooms: `total`, `pending`, `bills` |
| POST | `/residency/rooms/{id}/guests`, `/residency/guests/{id}/left` | `residency.manage` | `guest_name, guest_contact?, relation?, purpose?, host_occupant_id?, arrival_date, expected_departure_date?` |
| GET / POST | `/residency/requests[?status=open\|approved\|rejected\|cancelled\|all]`, `/residency/requests/{id}/decide` | view / manage | `decision (approve\|waitlist\|reject), location_id (approve), remarks?` |
| GET | `/residency/directory`, `/residency/analytics`, `/residency/unlinked` | `residency.view` | On / off campus staff; occupancy figures; imported staff without an account |
| GET / POST | `/residency/import/template`, `/import/preview`, `/import`, `/import/history`, `/import/{batch}/rollback` | `residency.manage` | CSV; all rows or none; undo while no bills exist |

## Phase 9a — store and purchasing

Rules: [`STORE.md`](STORE.md), [`PURCHASING.md`](PURCHASING.md). Under the `store` and `purchasing` module switches.

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/store/items[?search&category&low&inactive&format=csv]` | `store.view` | Items with `balance`, `low`; `meta.categories`, `meta.low_count` |
| GET | `/store/items/{id}` | `store.view` | Item with its ledger (newest first, `balance` after each row) |
| POST / PUT | `/store/items[/{id}]` | `store.manage` | `item_name, unit, category?, department_id?, reorder_level?, is_active?` |
| POST | `/store/items/{id}/movements` | `store.manage` | `movement_type (receive\|issue\|adjust), quantity (signed for adjust), unit_cost?, department_id?, issued_to?, movement_date?, remarks (adjust)` |
| GET | `/store/issues?from&to` | `store.view` | Issued, by department and item |
| GET / POST / PUT | `/purchasing/vendors[/{id}]` | view / manage | `name, contact_person?, phone?, email?, gstin?, address?, is_active?` |
| GET / POST | `/purchasing/quotations[?status&department_id]` | view / manage | `department_id, vendor_id, quotation_date, valid_until?, reference_no?, remarks?, lines[]` |
| POST | `/purchasing/quotations/{id}/decide` | manage | `decision (accept\|reject)`; accept returns the new draft order (201) |
| GET | `/purchasing/orders[?status=open\|draft\|…&vendor_id&department_id&search&page]`, `/purchasing/orders/{id}` | view, or `store.manage` | Detail: lines, receipts, invoices with payments, `received_value`, `invoiceable_amount`, `budget_left`, `can_manage`, `can_receive` |
| POST / PUT | `/purchasing/orders[/{id}]` | manage | Draft only: `department_id, vendor_id, order_date, expected_date?, terms?, lines[{item_id?, description?, quantity, unit?, rate, gst_percent?}]` |
| POST | `/purchasing/orders/{id}/issue`, `/cancel` | manage | Issue numbers the order; cancel takes `reason?` |
| POST | `/purchasing/orders/{id}/receive` | manage over the department, or `store.manage` | `received_date, remarks?, lines[{line_id, quantity}]` |
| POST | `/purchasing/orders/{id}/invoices` | manage | `invoice_number, invoice_date, goods_amount, other_charges?, remarks?` |
| DELETE / POST | `/purchasing/invoices/{id}`, `/purchasing/invoices/{id}/payments` | manage | Delete only without payments / `amount, payment_date, payment_type, payment_mode?, remarks?` |
| GET | `/me/work/materials` | signed in (with an employee record) | Active store items for a work entry; entries take `materials[{item_id, quantity}]` |
| GET | `/work/materials?from&to&department_id` | `daily_work.view` | Material usage report: `totals` and `rows` |

## Phase 9b — dead stock and the issue register

Rules: [`ASSETS.md`](ASSETS.md). Under the `assets` module switch. "org-wide" = the permission held with organization scope.

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/assets/masters[?inactive]` | `assets.view` | The item list; `meta.can_manage` |
| POST / PUT | `/assets/masters[/{id}]` | `assets.manage` org-wide | `item_name, category?, default_unit?, is_active?` |
| GET / POST | `/assets/masters/import/template`, `/import/preview`, `/import` | `assets.manage` org-wide | CSV; all rows or none |
| GET | `/assets/dead-stock[?department_id&category&condition&search&page&format=csv]` | `assets.view` scope (+ export for CSV) | Register |
| POST / PUT / DELETE | `/assets/dead-stock[/{id}]` | `assets.manage` scope | `department_id, item_master_id, quantity, unit?, purchase_date?, purchase_cost? (required for a new purchase), is_new_purchase, condition, location?, remarks?` |
| GET | `/assets/dead-stock/report` | `assets.view` scope | By department, category, condition; total value |
| GET / POST / PUT | `/assets/allocation-types[/{id}]` | view / manage, org-wide | `name, is_loan, fields[{label, type (text\|number\|date\|dropdown\|yesno), options?, required?, key?}], is_active?` |
| GET / POST | `/assets/allocation-items[?type_id&free]`, `/assets/allocation-items/{id}/retire` | view / manage, org-wide | `type_id, name, default_location?`; items show who holds them |
| GET | `/assets/allocations[?view=current\|loans\|overdue\|history&type_id&user_id&search&page&format=csv]` | `assets.view` org-wide | `meta.overdue` |
| POST | `/assets/allocations` | `assets.manage` org-wide | `item_id, user_id, purpose?, allocated_at?, due_at?, custom{key: value}` |
| POST | `/assets/allocations/{id}/return`, `/transfer` | `assets.manage` org-wide | `reason?` / `user_id, reason?` |
| GET | `/assets/allocations/report` | `assets.view` org-wide | Held, late, free items, by type, by holder |
| GET / POST | `/assets/allocations/import/template`, `/import/preview`, `/import` | `assets.manage` org-wide | CSV `type_name, item_name, default_location, is_loan` |
| GET | `/my/assets` | signed in | What the caller holds and returned |

## Phase 9c — IT department

Rules: [`IT.md`](IT.md). Under the `it` module switch. "seen" = `it.view`, `it.manage` or the lab's assistant (their labs only).

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/it/lookup?tag` | signed in | An asset by its tag (to report a problem) |
| POST | `/it/assets/{id}/report` | signed in | `issue_description` (5+ characters) |
| GET | `/my/it-tickets` | signed in | Problems the caller reported |
| GET | `/it/dashboard`, `/it/labs`, `/it/smartboards` | seen | Overview with labs, counts and warranties ending in 30 days |
| POST / PUT | `/it/labs[/{id}]` | `it.manage` | `name, location?, capacity?, department_id?, assistant_user_id?, is_active?` |
| GET | `/it/assets[?lab_id&asset_type&status&search&page&format=csv]`, `/it/assets/{id}` | seen | Detail with problems and moves |
| POST / PUT | `/it/assets[/{id}]` | `it.manage` or the lab's assistant | `lab_id, asset_type` (create), `workstation_no?, brand?, model?, serial_no?, purchase_date?, purchase_cost?, warranty_expiry?, os_license?, license_expiry?, remarks?` |
| POST | `/it/assets/{id}/status` | `it.manage` or the lab's assistant | `status?, power_status? (on\|off)` |
| POST | `/it/assets/{id}/transfer` | `it.manage` | `to_lab_id, remarks?` |
| GET | `/it/tickets[?status=open\|pending\|in_progress\|resolved\|all]` | seen | |
| POST | `/it/tickets/{id}/start`, `/it/tickets/{id}/resolve` | `it.manage` | resolve: `resolution_notes` |
| GET / POST | `/it/import/template`, `/it/import/preview`, `/it/import` | `it.manage` | CSV; missing labs created; all rows or none |

## Phase 9d — CCTV

Rules: [`CCTV.md`](CCTV.md). Under the `cctv` module switch. "any" = `cctv.view`, `cctv.check` or `cctv.manage`.

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/cctv/dashboard` | any | Recorders with their latest check, `late`, camera counts, `checked_today` |
| GET | `/cctv/dvrs/{id}` | any | Cameras and the last 30 checks |
| POST / PUT | `/cctv/dvrs[/{id}]` | `cctv.manage` | `name, location?, department_id?, channel_capacity?, storage_tb?, installed_date?, is_active?` |
| POST / PUT | `/cctv/dvrs/{id}/cameras`, `/cctv/cameras/{id}` | `cctv.manage` | `name, location?, camera_type (dome\|bullet\|ptz\|other), is_active?` |
| GET | `/cctv/dvrs/{id}/checkup?date` | any | The day's sheet (saved or all working) |
| POST | `/cctv/dvrs/{id}/checkup` | `cctv.check` or `cctv.manage` | `date, storage_used_percent?, footage_days?, backup_done, backup_location?, remarks?, cameras[{camera_id, status (working\|not_working\|needs_attention), remarks?}]` |
| GET | `/cctv/report?period=weekly\|monthly` | any | Per recorder: checks, share of days, backups, fullest storage, camera results |
| GET / POST | `/cctv/import/template`, `/import/preview`, `/import` | `cctv.manage` | CSV, one row per camera |

## Phase 10a — activity log and global search

Rules: [`AUDIT.md`](AUDIT.md), [`SEARCH.md`](SEARCH.md).

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/audit-logs?user_id&module&action&subject_id&from&to&page[&format=csv]` | `audit.view` organization-wide (CSV: `audit.export`) | 50 per page; `meta.modules`, `meta.can_export` |
| GET | `/audit-logs/logins?user_id&action&from&to&page[&format=csv]` | (the same) | Sign-in events of the organization's members; `meta.summary` (`logins_7d`, `users_7d`, `failed_7d`) |
| GET | `/audit-logs/users` | (the same) | People for the "who" filter |
| GET | `/search?q` | signed in (60 / minute) | `q` 2–100 characters; `data.{employees,students,letters,vehicles,renewals,store,it}[{id,title,subtitle,url}]`, only groups with results |

## Phase 10b — reports

Rules: [`REPORTS.md`](REPORTS.md).

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/work/print?from&to&department_id&employee_id&status` | `daily_work.view` | `data.{from,to,total,truncated,summary[],entries[]}`; up to 2,000 entries |
| GET | `/reports/campuses?from&to[&format=csv]` | `institutions.view` (organization or campus wide) | `data.{from,to,today,metrics[],campuses[]}`; switched-off modules' figures left out |

## Phase 10c — backup, restore, organization data

Rules: [`BACKUP.md`](BACKUP.md). "Confirm" = `password` (the person's own) and `confirmation` (the exact phrase).

| Method | Path | Who | Notes |
|---|---|---|---|
| GET | `/backups` | super admin | `[{name, kind (manual\|scheduled\|uploaded\|pre_restore\|pre_clear), created_at, size}]`, newest first |
| POST | `/backups` | super admin | Make a backup now (6 / minute) |
| POST | `/backups/upload` | super admin | `file` (gzip, made by this ERP; up to 500 MB, host limits apply) |
| GET / DELETE | `/backups/{name}` | super admin | Download / delete |
| POST | `/backups/{name}/restore` | super admin | Confirm with `RESTORE`; returns `safety_backup` |
| GET | `/organization/export` | `organizations.edit` organization-wide | JSON download |
| POST | `/organization/clear` | (the same) | Confirm with the organization's name; returns `deleted` per table and `safety_backup` |

