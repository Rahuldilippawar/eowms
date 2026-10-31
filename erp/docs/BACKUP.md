# Backup, restore and clearing data (Phase 10c)

## Whole-database backups (super admins)

**Admin → Backups** (`/admin/backups`).

- A backup is the whole database (every organization) as gzip-compressed SQL, written by the app
  itself, so it also works on hosting that does not allow `mysqldump`. Session, cache and job tables
  are left out.
- Files are kept in `storage/app/private/backups`, outside the website's public folder. They are
  downloaded only through the app, by a super admin; each make, download, upload, delete and restore
  is in the activity log.
- **Nightly**: `erp:backup` at 02:50 India time (needs the scheduler cron, see `WORK-AND-TASKS.md`).
  The newest 14 nightly backups are kept; older nightly ones are removed. Manual, uploaded and
  safety backups stay until someone deletes them.
- **Back up now** makes a manual one; **Upload** adds a backup file made by this ERP (anything else is
  refused: the file must start with the ERP backup header).
- Backups hold the **database only**. Uploaded files (work photos, letter and renewal attachments,
  employee photos and documents, leave documents) stay in `storage/app/private`; include that folder
  in the hosting's own backups.

## Restore (super admins)

- Replaces the **whole database** (every organization) with the chosen backup.
- Needs the person's password again and `RESTORE` typed.
- First a **safety backup** of the database as it is is taken ("Before a restore"). While the
  restore runs the app is in maintenance mode for everyone. If loading fails part-way, the safety
  backup is loaded back. After loading, any newer migrations are run, so an older backup comes up to
  the current schema.
- Restore on the same installation (the same `APP_KEY`): anonymous grievance markers are tied to it.
- Large databases: the host's PHP time and upload limits apply; for very large restores use the
  hosting panel's database import with the downloaded file (it is plain SQL once unzipped).

## An organization's data (organization admins)

**Administration → Export / clear data** (`/organization/data`). Needs `organizations.edit`
**organization-wide**.

- **Export**: one JSON file with every table's rows of this organization, and its people's accounts
  (name, e-mail, active, last sign-in). Left out: passwords and tokens, private employee details
  (Aadhaar, bank), the activity log, and anonymous grievance markers. It is for keeping or moving
  the data; there is no per-organization restore (organizations share one database, so putting one
  back could clash with another's records; a whole-database restore is the way back).
- **Clear data**: deletes the organization's working data — people records, attendance, leave,
  work, tasks, letters, finance, renewals, vehicles, hostels, housing, store, purchasing, assets, IT,
  CCTV, requests, grievances and notifications — and the rows that belonged to them.
  - Kept: the organization, campuses, departments, user accounts, roles and access, settings
    (custom fields, leave types, letter categories, approval workflows, holidays, numbering) and the
    activity log.
  - Needs the password and the organization's exact name typed. A safety backup of the whole
    database is taken first ("Before clearing"); a super admin can restore it.
  - Uploaded files are not deleted, so a restore brings everything back as it was.
