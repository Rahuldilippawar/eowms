# Renewals (Phase 7b)

Licences, approvals, affiliations, insurance, AMCs and contracts: anything with an expiry date that
must be renewed on time. All rules run on the server.

## Items and status

- An item belongs to a department (scope follows it): type (free text, e.g. "Fire NOC"), optional
  regulatory body (CBSE, AICTE, University, RTO/Transport, …), name, reference, issued and expiry
  dates, renewal cost, vendor, and a responsible person (an active colleague).
- Status is worked out from today in the organization's timezone: **expired** (after the expiry
  date), **due soon** (within 30 days), **valid**. An item can be switched off ("not tracked")
  instead of being deleted.
- **Renew**: a later expiry date, optionally a new cost, vendor and reference. The old cycle (dates,
  cost, vendor, reference, who renewed) moves to the history. Editing never moves the expiry date
  forward silently — the form keeps it fixed and points to Renew.

## Payments and the budget

- Payments are recorded against the **current cycle** (its expiry date); "paid / still due" is shown
  for the cycle. After renewing, the new cycle starts at zero.
- **Monthly bills** (rent, AMC instalments): a monthly amount and a start month; every payment names
  the month it covers, and the item lists the months still unpaid.
- Every payment is charged to the department budget through the finance ledger
  (`source_type = renewal_payment`, category "renewal"), in the financial year of the payment date.

## Documents, calendar, costs, import

- Documents (PDF, images, Word, Excel up to 10 MB) are private and tagged with the cycle they belong
  to; downloads are audited.
- The calendar lists what expires in a chosen month, day by day.
- Cost analysis: cost of each cycle by the year it expires (current cycles and the history) per type,
  and what was actually paid each year.
- CSV import (`department_code, item_type, regulatory_body, item_name, reference_no, issued_date,
  expiry_date, renewal_cost, vendor_name, vendor_contact, responsible_employee_code`): every row is
  checked first, then all rows or none. CSV export needs `renewals.export`.

## Reminders

`erp:renewal-reminders` runs daily (08:00 India time): 90, 60, 30, 15, 7, 3, 1 and 0 days before
expiry, then 1 day after and every 7 days until renewed; a missed run catches up within 2 days. The
responsible person is told; from 7 days before (or when nobody is responsible) the department head
too; if neither exists, whoever created the item. Each item, milestone and expiry date is reminded
only once, so a renewal starts a fresh set.

## Permissions

`renewals.view`, `renewals.manage` (create, edit, renew, pay, attach, import), `renewals.export`, all
following the department scope. The default Principal role gets all three; HOD gets view and manage.
