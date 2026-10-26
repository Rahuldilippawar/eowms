# Daily work, tasks, notifications and performance (Phase 5)

All rules run on the server; the app only shows what the API allows.

## Daily work log

- Every employee logs their own work: title, optional details, optional hours (0.25–24, in quarter
  hours), status (completed / in progress / pending) and optionally one of **their own** tasks.
- Allowed dates: today and the last `daily_work.edit_days` days (organization setting, default 3,
  "today" in the organization's timezone). No future dates. The hours of one date never add up to
  more than 24 (checked under a row lock, so two quick saves cannot slip past it).
- An entry can be edited or deleted by its owner inside the same window, **until it is reviewed**.
- **Review**: `daily_work.review` with a scope that reaches the employee; never one's own work. The
  employee gets a notification. `daily_work.view` shows the team list; `daily_work.export` adds the CSV
  (UTF-8 with BOM, formula cells neutralised, export written to the audit log).

### Team, requester, cost and photos (Phase 5A-2b)

- **Done together with**: up to 20 active colleagues of the organization (never oneself). The
  employee finds them with `/me/work/colleagues?search=` (name, code and department only). Leaving
  `team_employee_ids` out of an update keeps the team as it is.
- **Requested by** (free text) and **estimated / actual cost** (₹, 0 or more) are optional and appear
  in the CSV export.
- **Before / after photo**: JPEG, PNG or WebP up to 5 MB, private storage, only while the entry can be
  edited. The photo is served to the employee, their team mates and anyone whose `daily_work.view`
  scope reaches the employee; anyone else gets 404. Deleting the entry deletes its photos.

### Old work records (import)

`daily_work.import` brings the old app's records in from CSV: `date, employee_code, title` (required),
`description, hours, status, requested_by, team_codes, estimated_cost, actual_cost`. The employee must
be within the importer's scope; colleagues in `team_codes` (comma separated) must be active. Old dates
are allowed (the edit window does not apply), future dates are not, status defaults to completed.
Every row is checked first, then all rows are written or none (up to 5,000). Imported entries are
marked "Old record" and can no longer be edited by the employee once outside the window.

## Tasks

| Who | Can |
|---|---|
| **Assigner** — the employee's reporting officer, or anyone whose `tasks.create` scope reaches the employee | create a task for an active employee who is not themself |
| **Manager** — the assigner, the assignee's reporting officer, or a `tasks.edit` holder whose scope reaches the assignee | edit (title, details, priority, due date, reassign), verify and close, send back, cancel |
| **Assignee** | start, mark done, comment |
| `tasks.view` within scope | see the tasks (read only) |

The assignee can never manage their own task. Somebody who cannot see a task gets 404.

```
open ──start──▶ in_progress ──submit──▶ submitted ──complete──▶ completed
  │                  ▲                      │
  └──submit──────────┼──────────────────────┤
                     └──────reopen──────────┘   (reason required)
open / in_progress / submitted ──cancel──▶ cancelled   (reason required)
```

- Every change is a timeline entry (who, when, note) and an audit-log entry; transitions lock the row,
  so two people acting at once cannot both succeed (the second gets "state changed, refresh").
- A due date cannot be in the past when a task is created. A task is **overdue** when it is still
  open, in progress or submitted after its due date.
- Codes are `TSK-00001`, unique per organization.
- **Money spent** (`cost_incurred`, ₹) can be given when the assignee marks the task done or the
  manager closes it; leaving it out keeps the earlier amount (Phase 5A-2b).

## Notifications

In-app only for now (bell in the top bar, a full list at `/notifications`). The app polls the unread
count every minute — shared hosting has no websockets. The server stores a type and its data, and the
app writes the sentence in the reader's language. Nobody is notified about their own action and
inactive accounts get nothing.

| Event | Who is told |
|---|---|
| task assigned / reassigned away | the (new / previous) assignee |
| task started, marked done, commented by the assignee | the assigner |
| task closed, sent back, cancelled, commented by a manager | the assignee |
| task due tomorrow | the assignee (once) |
| task overdue | the assignee and the assigner (once per due date) |
| leave applied | the reporting officer |
| leave approved / rejected | the applicant |
| attendance correction decided | the employee |
| work entry reviewed | the employee |

Reminders come from `php artisan erp:task-reminders`, scheduled hourly. On Hostinger add one cron job:
`* * * * * cd /home/<user>/erp-app && php artisan schedule:run >> /dev/null 2>&1`. Each reminder has a
dedupe key, so running it more often never sends duplicates. Email and push delivery are not part of
this phase.

## Performance report

Per active employee within `performance.view` (and for oneself at `/me/performance`), for a date range
of up to one year (default: this month):

- **Attendance**: days present (present + late + half day), of which late, half days, absent records,
  leave days. Days without any record are not counted as absent, because there is no holiday or
  weekly-off calendar yet.
- **Work log**: entries, hours, distinct days logged, reviewed entries.
- **Tasks**: tasks whose **due date** is in the range, cancelled ones left out: assigned, completed,
  completed on time (closed on or before the due date), overdue (still open after the due date), and
  on-time rate = on time ÷ assigned.

`performance.export` adds the CSV.
