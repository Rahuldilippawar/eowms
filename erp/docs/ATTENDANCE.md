# Attendance and leave rules (Phase 4)

All rules run on the server; the browser only displays what the API returns.

## Time

- **Server time only.** Check-in and check-out use the server clock. The browser never sends a time
  for its own attendance, so a wrong phone clock cannot change a record.
- **Organization timezone.** Each organization has `settings.attendance`:

  | key | default | meaning |
  |---|---|---|
  | `timezone` | `Asia/Kolkata` | Local day boundaries and the work-start time are read in this zone |
  | `work_start` | `09:30` | Start of the working day |
  | `late_grace_minutes` | `15` | A check-in after `work_start` + grace is **late** |
  | `half_day_below_hours` | `4` | A check-out with fewer worked hours turns the day into **half day** |
  | `max_shift_hours` | `18` | How long an open check-in can still be checked out |

## Statuses

`present`, `late`, `half_day`, `absent`, `leave`. There is one record per employee per date
(database unique key). The record keeps the employee's institution and department at that time, so
reports stay correct after a transfer.

## Self-service

- **Check-in** creates today's record (`present` or `late`). A second check-in the same day is
  refused, and so is a check-in on a day of approved leave. Two simultaneous requests cannot create
  two records (row lock + unique key).
- **Check-out** closes the latest open record within `max_shift_hours`. A night shift that starts
  on one date and ends after midnight stays on the start date.
- **Correction request**: an employee asks for a different status / times for a past or current
  date with a reason. It is decided by someone with `attendance.approve` in scope, never by the
  requester. Approving it writes the record (source `correction`).

## Marking by others

`PUT /attendance` with `attendance.create` (new record) or `attendance.edit` (existing record), for
employees inside the marker's scope, for today or a past date only. If the check-out time is earlier
than the check-in time it is taken as the next day. Every change is in the audit log with old and new
values.

## Leave

- Days are counted as **working days**: weekly offs (the employee's own, else the organization's) and
  holidays of the whole organization or the employee's institution are skipped. A range with no
  working day is refused.
- **Balance** per leave type and calendar year: allocated (set per employee with `leave.balances`, else
  the type's days per year) minus approved and pending working days in that year. A request that
  needs more than is left is refused. Unpaid types with 0 days per year (leave without pay) have no
  limit.
- A request cannot overlap the employee's own requested or approved leave.
- **Who decides:** the employee's reporting officer, or anyone with `leave.approve` whose scope
  covers the employee. Nobody decides their own request.
- **On approval** each working day of the leave gets an attendance record with status `leave`, except
  days the employee actually checked in (those are kept as worked).
- An employee can cancel their own request while it is still pending. An approved request is not
  cancelled from self-service yet; an attendance marker corrects the days if plans change.
- **Attachments** (PDF / JPG / PNG, up to 5 MB) are stored on the private disk, outside the web
  root, and are downloaded only through `GET /leave-requests/{id}/attachment`, which checks that the
  caller may see the request.

## Holidays, weekly offs and shifts (Phase 5A-2)

- **Holidays**: everyone sees the calendar; `attendance.manage` adds holidays for institutions in scope
  (organization-wide ones need organization scope), one by one or by CSV
  (`holiday_date, name, institution_code`; existing dates are skipped).
- **Organization rule** (`Organization settings → Attendance rules`, `organizations.edit`): timezone,
  work start / end, late grace, half-day threshold, longest shift, weekly offs (default Sunday) and the
  daily-work edit window.
- **Employee shift** (profile → Attendance, `attendance.manage` in scope): own start / end, grace,
  half-day threshold and weekly offs; empty fields follow the organization rule. Late and half day on
  check-in / check-out use the employee's own rule.

## Biometric machines (ZKTeco push / ADMS)

- Register each machine's serial number (`attendance.manage`, organization scope) and point the
  machine's cloud server to the site; it calls `/iclock/cdata`. Set each employee's **Machine PIN**.
- Machines have no credentials: only registered, active serial numbers are accepted, everything else
  gets a plain `OK` and is ignored. The endpoint is rate-limited.
- Punch times are the machine's local time, read in the organization's timezone. The earliest punch of
  the day is the check-in and the latest the check-out (late / half day by the employee's rule); a
  punch on a day already marked absent or leave turns it into a worked day. Every punch is stored once
  (machines resend logs); punches whose PIN matches nobody are listed on the machines page.
- Night shifts across midnight are not split from machine punches yet: punches count on their own
  calendar date.

## Daily jobs

Run by the scheduler (`php artisan schedule:run` every minute, see `WORK-AND-TASKS.md`); each is safe
to run again.

| Command | When | What |
|---|---|---|
| `erp:mark-absent` | hourly (for yesterday) | Absent for every active employee with a working day and no record at all |
| `erp:attendance-alerts` | daily | Reporting officer is told about 3+ late arrivals or 2+ absences in the last 7 days (once a week) |
| `erp:celebrations` | hourly | Birthday and work-anniversary wishes to the person and their reporting officer (once a year) |

## Reports

- **Month grid** (`attendance.view`): a code per employee and day — P present, L late, H half day,
  A absent, LV leave, WO weekly off, HO holiday, `-` working day with no record; CSV with
  `attendance.export`.
- **In / out register** (old movement report): times and hours per day, up to 92 days; CSV.
- **Attendance certificate**: printable totals for a period, for oneself or for an employee in scope.
- **Salary report** (`employees.sensitive` + `attendance.view`): pay days = days in month − absent −
  unpaid leave − working days with no record − half of each half day; pay = monthly salary × pay days
  ÷ days in month. Days still to come are not deducted. PF, ESIC and tax are not computed. Every view
  or download is audited.

## Dashboard

Each KPI and chart is returned only when the caller holds the permission behind it (otherwise
`null`, and the tile is hidden), and every count is limited to the caller's scope. "Absent today"
means active employees in scope with no record, or an `absent` record, for today.
