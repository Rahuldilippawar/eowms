# System status and database update (Phase 11c)

**Administration → System status** (`/admin/system`), super admins only.

- **Production checks**: debug mode off, the app address on HTTPS, e-mail set up (not `log`),
  push keys set, the scheduler ran in the last 10 minutes (a heartbeat the scheduler writes every
  minute), storage writable, a backup in the last 26 hours.
- **Facts**: version (`APP_VERSION`, set by the deploy), PHP, Laravel and database versions, app
  address, fingerprint domain, when the scheduler last ran, the latest backup.
- **Database update**: lists migrations not yet run. "Update the database" (password and `MIGRATE`
  typed) first takes a whole-database backup ("Before an update"), then runs them, and logs it. This
  replaces the old app's "DB update" screen: the Hostinger deploy is FTP-only, so there is no shell to
  run `php artisan migrate`. If an update fails, restore the backup from Admin → Backups.
- The old app's "maintenance mode" and "system update" screens are replaced by the deploy workflow
  and this page; a restore (Phase 10c) turns maintenance mode on by itself while it runs.
