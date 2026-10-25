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

- Days are counted as **calendar days, inclusive** (Mon–Tue = 2). There is no holiday or weekly-off
  calendar yet, so a range that covers a Sunday counts the Sunday; this changes when the holiday
  calendar arrives.
- A request cannot overlap the employee's own requested or approved leave.
- **Who decides:** the employee's reporting officer, or anyone with `leave.approve` whose scope
  covers the employee. Nobody decides their own request.
- **On approval** each leave day gets an attendance record with status `leave`, except days the
  employee actually checked in (those are kept as worked).
- An employee can cancel their own request while it is still pending. An approved request is not
  cancelled from self-service yet; an attendance marker corrects the days if plans change.
- **Attachments** (PDF / JPG / PNG, up to 5 MB) are stored on the private disk, outside the web
  root, and are downloaded only through `GET /leave-requests/{id}/attachment`, which checks that the
  caller may see the request.

## Dashboard

Each KPI and chart is returned only when the caller holds the permission behind it (otherwise
`null`, and the tile is hidden), and every count is limited to the caller's scope. "Absent today"
means active employees in scope with no record, or an `absent` record, for today.
