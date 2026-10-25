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
