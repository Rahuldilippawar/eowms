# Hostels (Phase 8c)

The old app's `hostels/` module. All rules run on the server; the module can be switched off per
organization (`hostels`).

## Who does what

| | Sees | Looks after residents (beds, roll calls, out-passes, health, fees, departure, import) | Hostel, rooms, staff |
|---|---|---|---|
| The hostel's **staff** (wardens) | that hostel | yes | no |
| `hostels.view` | hostels of the departments in scope | — | — |
| `hostels.care` | (with view) | hostels in scope | no |
| `hostels.manage` | (with view) | hostels in scope | yes |

Hostel staff need no hostel permission: the menu shows Hostels to anyone on an active hostel's
staff (`hostel_staff` on `/auth/me`). `hostels.export` gives the residents CSV.

## Hostels, rooms, beds

- A hostel belongs to the department that runs it: name (unique), boys / girls / mixed, capacity.
- Rooms have a number (unique in the hostel) and a number of beds. A room's beds cannot be cut
  below the residents in it, and a room with residents cannot be taken out of use.
- A resident has at most one bed; giving another bed frees the old one the same day. A full room
  cannot take more, and a named bed (A, B…) cannot be given twice.

## Residents

- Often pupils of other schools, so a record of their own: name, school, class, district,
  gender, date of birth, admission date, guardian and phone (optionally linked to a student).
- CSV import: `name, school_name, class_grade, district, gender, admission_date, date_of_birth,
  guardian_name, guardian_phone`; gender as male / female (also मुलगा / मुलगी, boy / girl), dates
  as YYYY-MM-DD or DD/MM/YYYY; every row checked first, then all rows or none.
- **Leaving**: departure date, reason, what they do now, contact, notes. The bed is freed, an open
  out-pass is closed, and the resident moves to the alumni list, where the contact details can be
  kept up to date.

## Roll calls

- Three a day: morning, afternoon, evening. The sheet lists the active residents admitted by that
  day; residents out on an out-pass that day start as "on leave", everyone else as present.
- Saving again overwrites the session. The last 7 days can be corrected; the future cannot be
  marked.
- `php artisan erp:hostel-attendance-check` (daily, 20:00 India time): for each active hostel with
  residents, roll calls of the day that were never taken are reported to the hostel staff and the
  head of the department running it, once a day.

## Out-passes, health, fees

- **Out-pass**: leaving date, expected return, reason, who took them; one open at a time. Marking
  the return closes it; past the expected return it shows as late (on the hostel and its list).
- **Health**: blood group, allergies, regular medicine, emergency contact, notes, and a log of
  illnesses / injuries with what was done. The audit log records that health details changed,
  not what they are.
- **Fees**: an amount due for a period (month, term…) and payments (cash, online, cheque, other)
  that never exceed the balance; not paid / part paid / paid.

## Dashboard

Per hostel: residents, beds used, who is out (and late), fees due, and today's three roll calls
(present / absent / on leave, or not taken).
