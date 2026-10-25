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
