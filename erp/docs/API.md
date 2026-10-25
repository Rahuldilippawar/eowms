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
| GET | `/institutions` | `institutions.view` | Query `search, type, is_active, per_page (≤100), page`. Scoped. |
| GET | `/institutions/{id}` | `institutions.view` | 404 outside organization or scope |
| GET | `/departments` | `departments.view` | Query `search, institution_id, central, is_active, per_page, page`. Scoped. |
| GET | `/departments/{id}` | `departments.view` | 404 outside organization or scope |

Every security-relevant action is written to `audit_logs` (append-only): login, failed login,
logout, organization switch, password-reset request, password reset.
