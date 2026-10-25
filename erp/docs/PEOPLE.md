# Staff profiles, import, ID cards, custom fields, modules (Phase 5A-1)

Old-app parity for the staff module — see `docs/erp/PARITY.md`.

## Profile

| Part | Who sees | Who changes |
|---|---|---|
| Basic record (code, name, designation, department, reporting officer, login link) | `employees.view` in scope, the employee | `employees.edit` in scope |
| HR profile: second designation, employment type, group, teaching / non-teaching, father / mother name, gender, date of birth, marital status, blood group, WhatsApp, addresses, religion, caste + validity no., disability, emergency contact, date of leaving | same | `employees.edit` in scope; the employee changes their **own** WhatsApp, addresses, blood group and emergency contact |
| Organization-defined fields (see below) | same | `employees.edit` in scope |
| **Private**: Aadhaar, ration card, bank account + IFSC / bank / branch, PF + UAN, ESIC, professional tax, monthly salary | `employees.sensitive` in scope, the employee | `employees.sensitive` in scope |
| Photo | as basic record | `employees.edit` in scope, the employee |
| Documents (Aadhaar, PAN, education, experience, appointment, medical, bank, other) | as basic record | `employees.edit` in scope, the employee (own uploads) |
| Family members (name, relation, date of birth, phone, lives on campus) | as basic record | `employees.edit` in scope, the employee |
| Achievements | as basic record | `employees.edit` in scope |

- Private data lives in its own table, so the ordinary employee list and record never carry it.
- **Aadhaar and bank account numbers are encrypted at rest with the app key (`APP_KEY`).** Keep that
  key safe and never change it on a server with data: without it those numbers cannot be read back.
- They are shown masked (`••••••••9012`). "Show full numbers" returns them and writes an audit entry.
  Audit entries for private changes list only the field names, never the values.
- Files (photos up to 2 MB, documents up to 5 MB: PDF / JPG / PNG) are stored on the private disk and
  served only through permission-checked endpoints; downloads are audited.

## Staff import (CSV)

1. Download the template. Columns: `employee_code`, `name`, `department_code` (required),
   `institution_code` (needed only when a department code exists in more than one institution),
   `designation`, `phone`, `email`, `joining_date`, `gender`, `date_of_birth`, `father_name`,
   `mother_name`, `whatsapp`, `employment_type`, `reporting_officer_code`, `local_address`.
2. Upload: every row is checked (code unique in the organization and in the file, department exists
   and is within your `employees.create` scope, dates like `2026-06-30` or `30/06/2026`, reporting
   officer is an existing employee or another row). Nothing is written.
3. Import: all rows in one transaction, or none if any row still has a problem. Up to 2,000 rows,
   comma or semicolon separated, UTF-8 (Excel's "CSV UTF-8").

## ID cards and QR verification

- Print cards for a department or for chosen people (`employees.view` in scope). Each card has a QR
  code with a link to `/verify/employee/<token>`; the token is a random 26-character id, not the
  employee id.
- The public page (no login, 30 requests a minute) shows only what the card shows — name, code,
  designation, department, institution, photo — and whether the person still works there (active and
  no date of leaving).

## Moving staff

Select people on the ID-card screen and move them to another department (`employees.edit` with a
scope over each person and the target department). The new primary department is removed from their
additional departments; each move is audited with the reason.

## Organization-defined fields

`Organization → Custom fields` (`organizations.edit` at organization scope) adds fields to the employee
profile, the student form and the daily work entry: text, long text, number, date, choice list or
yes / no, optionally required. The server validates the values and ignores unknown keys. Key and type
cannot change after creation; switching a field off keeps the stored values. The old app's community
fields (ashram resident, naam dharak, food preference, …) become such fields for that organization.

## Modules on / off

`Organization settings → Modules` switches off students, attendance, leave, daily work, tasks or
performance for the organization. Their permissions then count as not held, self-service endpoints
answer 403 and the menu entries disappear; data is kept and comes back when switched on.

## Own password and forced change

Everyone can change their password from the user menu (current password required; other sessions are
signed out). Accounts flagged "must change password" (imported accounts, Phase 11) can only change the
password or sign out until they do.
