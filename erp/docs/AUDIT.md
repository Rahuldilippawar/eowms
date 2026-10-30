# Activity and sign-in log (Phase 10a)

Every change the API makes has been written to the append-only audit log since Phase 2 (who, what,
which record, the changed fields, IP address and browser). Phase 10a adds the screens to read it.

## Who sees it

- `audit.view` held **organization-wide** (the log spans every department, so a department- or
  campus-wide grant is refused). The Organization Admin role has it.
- `audit.export` also allows the CSV download (up to 10,000 rows per download). Each download is itself
  logged.

## Activity

- The organization's entries, newest first, 50 per page; filter by person, module and dates (the
  organization's days).
- Each entry shows the module and action, the record (type and number) and, when fields changed, the
  old and new values.
- What never shows: passwords and other hidden fields are never written; private employee details and
  hostel health records log only *that* they changed; an anonymous grievance's own steps are not logged.

## Sign-ins

- Sign-in, wrong password, sign-out, password change and reset requests of the people who belong to
  this organization (they hold a role in it), with IP address and browser.
- Totals for the last 7 days: sign-ins, people who signed in, and wrong passwords (in red when any).
