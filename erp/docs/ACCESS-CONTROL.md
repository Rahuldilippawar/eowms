# Access control and tenant isolation

## Model

```
organizations ─┬─ institutions (college | school | other)
               ├─ departments (institution_id NULL = central department)
               ├─ roles ── role_permission (permission + scope)
               └─ role_assignments (user + role [+ institutions] [+ departments])
```

- **Permissions** are `module.action` keys declared in `backend/config/permissions.php` and
  synced to the `permissions` table (`php artisan erp:sync-permissions`; never deletes).
- **Roles** belong to one organization. Every new organization gets the system roles in
  `App\Support\Access\DefaultRoles` (Organization Admin, Principal, Head of Department, Staff).
- **Scopes** (`App\Enums\PermissionScope`) say which records a granted permission reaches:

| Scope | Reaches |
|---|---|
| `organization` | every record of the current organization |
| `institutions` | records of the institutions listed on the role assignment (and their departments) |
| `departments` | records of the departments listed on the role assignment |
| `own` | records the user created (`created_by`) |
| `assigned` | records assigned to the user (module-specific column) |

  When several roles grant the same permission, the broadest scope wins and the institution /
  department ids of the winning assignments are combined.
- **Global administration** exists only as `users.is_super_admin`: a super admin may switch into
  any active organization and has every permission there. Nobody else can act outside the
  organizations they hold a role in.

## Enforcement (backend, independent of the SPA)

1. `auth:sanctum` — session cookie (first-party SPA) only.
2. `SetAccessContext` middleware — runs **before route-model binding**:
   signs out deactivated users; picks the current organization from the session, re-validated
   against the user's memberships on every request (a tampered or stale session value is
   ignored); inactive organizations are never selectable.
3. `BelongsToOrganization` global scope — every query on an organization-owned model is limited
   to the current organization, so another organization's id simply resolves to **404**.
4. `permission:<key>` route middleware — **403** when the permission is not held at all.
5. `ScopedQueries` / `ResolvedScope::apply()` — list and detail queries are narrowed to the
   user's scope; records outside it are **404** (not 403, so ids cannot be probed).

The SPA receives the user's permission keys from `/auth/me` only to hide controls.

## Tests

`backend/tests/Feature/Api/TenantIsolationTest.php` and `PermissionScopeTest.php` cover:
listing and fetching another organization's records by id, filter manipulation, switching into an
organization without membership, a tampered session organization, inactive organizations,
super-admin switching, each scope, empty scopes and combining roles.
The Playwright suite (`frontend/e2e/tenancy.spec.ts`) repeats the key checks through the browser.
