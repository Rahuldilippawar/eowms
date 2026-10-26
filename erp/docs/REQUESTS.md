# Work requests, public form, grievances, escalation (Phase 8b)

All rules run on the server. Modules `requests` and `grievances` can be switched off per
organization (switching off `requests` also closes the public form).

## Work requests

- **Anyone** signed in raises a request for a department: kind of work (general, IT,
  maintenance, housekeeping, other), title, details, where, priority and a contact number
  (their profile phone by default). An IT request names the IT problem and goes to the IT
  department (code `IT`) unless another is chosen.
- Numbering: `REQ-YY-NNNN` per organization and calendar year (row-locked sequence).
- **SLA by priority** (as in the old app): urgent 8 hours, high 1 day, normal 3 days, low 7 days.
- Who sees what:
  - the requester sees their own requests (`My requests`);
  - `requests.view` sees what the departments in scope receive (`Department queue`, with
    pending / in progress / past due / completed counts);
  - the person a request is assigned to sees it.
- Who does what:
  - `requests.manage` over the receiving department assigns it to a colleague of that
    department and works it;
  - the assignee works it (status, cost, remarks) but cannot pass it on;
  - completing needs a note of what was done.
- The requester can **cancel** a request nobody has started, **close** a completed one with a
  1–5 rating and feedback, or **reopen** it within 7 days of completion (the SLA starts again).
- Every step is in the action log; comments go to the other side; opening a request as a
  handler records that it was seen.
- Report for a period: by status, kind and receiving department (done, past due, average hours
  to finish, cost, rating). CSV with `requests.export`.

## Public form and tracker

- `/request/{organization code}` (no login): name, phone (10 digits), department, kind, where,
  what is needed. A hidden honeypot field catches bots — they get the usual "received" answer and
  nothing is saved. Each address may send 5 requests an hour.
- `/request/{organization code}/track`: the request number **and** the phone number given with it
  must both match; the phone alone never lists anything. It shows the status, the department,
  when the department first opened it, the completion note and the status changes — no staff
  names or internal remarks.

## Grievances

- Anyone signed in submits a grievance (subject, what happened), optionally **anonymously**:
  then the grievance stores no user, only a keyed hash (HMAC with the app key), so the sender
  still sees and follows up their own grievance while nobody else — handlers included — can tell
  who sent it. Audit rows carry the acting user, so an anonymous sender's own steps are not
  audited. Changing `APP_KEY` would cut senders off from their earlier anonymous grievances (do
  not change it on a server with data).
- `grievances.manage` holders see and handle all grievances: status, resolution (needed to
  resolve), assignment. The assignee can change status and resolution.
- Comments between sender and handlers; the sender can reopen a resolved or closed grievance
  (the 7-day deadline starts again). The sender hears about status changes and replies (not when
  anonymous: there is nobody to address).

## Escalation

`php artisan erp:escalate-tickets` (hourly):

- a request past its SLA goes to the receiving department head (level 1); past twice its SLA, also
  to everyone holding `requests.manage` across the organization (level 2); each level once,
  logged on the request;
- a grievance past its 7-day deadline goes to the grievance handlers and its assignee, once;
- each person gets **one** summary per run, however many items are late.

## Permissions

`requests.view`, `requests.manage`, `requests.export` (department scope) and `grievances.manage`.
The default Principal role gets all; HOD gets requests view and manage.
