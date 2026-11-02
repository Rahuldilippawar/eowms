# Importing the old app's data (Phase 11d)

The importer brings the old app's data (both organizations) into the ERP. It only ever **reads a copy**
of the old database; the live old app is never touched and keeps running until cutover.

## For the owner: steps on Hostinger

1. **Make the copy.** In hPanel → Databases, create a new empty database (e.g. `…_eowms_copy`) and a
   user for it. In phpMyAdmin, export the live old database (Export → Quick → SQL), then open the new
   database and Import that file.
2. **Point the ERP at it.** In the ERP's server `.env` (staging first) set
   `LEGACY_DB_DATABASE`, `LEGACY_DB_USERNAME`, `LEGACY_DB_PASSWORD` (and `LEGACY_DB_HOST` if it is not
   the same server). These stay on the server; never send them in chat or e-mail.
3. **Dry run.** As super admin: Administration → Old data import → **Dry run**. It shows, per step, how
   many rows were read, would be new, were already imported, would join an existing ERP record, and
   were skipped (with the reason), plus notes. Nothing is written.
4. **Fix and repeat** until the report looks right (fixes go in the old app or the copy).
5. **Import.** **Import** (password and `IMPORT` typed). A whole-database backup is taken first; the
   whole import is one transaction, so it either completes or leaves nothing. It can be run again
   later (e.g. after a fresh copy just before cutover): rows already imported are skipped.

The same works from a shell: `php artisan erp:legacy-import --dry-run` / `php artisan erp:legacy-import`.

## What moves (11d-1: structure and people)

| Old | ERP | Notes |
|---|---|---|
| organizations | organizations | Code from the old slug; the starter roles are created |
| campuses | institutions | Primary / secondary school, junior college → school; Trust HQ / other → other; the rest → college |
| departments | departments | The Marathi name when there was one; heads are set after the people |
| users | accounts | Same e-mail (lower case). **Passwords move as they are** (bcrypt), so everyone keeps their password; an account whose password is not bcrypt must reset it (noted in the report). An e-mail the ERP already has joins that account. |
| users.role | roles | `super_admin` → super admin; management roles → Principal (the old *principal* for their campus, the others for every campus); hod / supervisor / coordinator → Head of Department; staff → Staff. Extra department / campus access adds to their reach. Review the roles after importing. |
| users (staff details) | employees | Code, attendance-machine number, designation, contact, placement, personal details; **Aadhaar and bank details encrypted**; salary and PF / ESIC / PT; the old community fields (ashram resident, naam dharak, food preference, health and home schemes, ...) become employee custom fields. People without a department go to a "विभाग नाही (जुना data)" department of their campus. |
| employee_family_members, staff_achievements | family, achievements | |
| students | students | Admission number as the student code; the old student portal sign-in comes with Phase 14 |

Later parts: 11d-2 attendance, leave, holidays, biometric, daily work, tasks; 11d-3 letters and
signatures, finance and budgets, renewals, vehicles, maintenance, work requests, grievances; 11d-4
hostels, residency, store, purchasing, assets, IT, CCTV and uploaded files. Modules of Phases 12–14
(announcements, gaushala, music, academics, ...) are imported when those modules exist.

## How it works

- `legacy_id_map` records which old row became which ERP row; every step looks there first.
- `legacy_import_runs` keeps each run's report (shown on the page and in `php artisan` output) and the
  safety backup's name. Each run is in the activity log.
- The report keeps every count and up to 200 skipped rows / notes per step.
