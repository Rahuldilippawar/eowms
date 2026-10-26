# Department finance (Phase 7a)

All rules run on the server; the app only shows what the API allows.

## Budgets and the spend ledger

- A **budget** is set per department and financial year (April–March, named by its start year:
  `2026` = April 2026 – March 2027) with `finance.budget` whose scope reaches the department.
- Every spend that belongs to a department is **one ledger row** (`budget_transactions`) tied to its
  source record (`source_type` + `source_id`), in the financial year of the spend's **own date** (a
  purchase dated 31 March counts in the year that ended that day). Changing the source updates its
  row; deleting it (or an amount of 0) removes the row — the ledger never double-counts.
- Sources now: **material purchases** (their total) and **approved budget letters** (the approved
  amount, charged when the last holder signs). Fuel, maintenance and renewal payments join as those
  modules arrive.
- Going over budget is **allowed but visible**: the purchase form warns before saving, and the
  budgets page shows "over by …" in red. `GET /finance/budget-check` answers "what is left?" for a
  department and date.

## Material purchases and payments

- `finance.manage` (scope reaching the department) records a purchase: material, vendor, quantity ×
  rate, GST %, transport, date (not in the future). The server computes base, GST and total.
- Payments (advance / part / final, mode, date) are recorded against the purchase; a payment can
  never exceed what is still due, and the purchase total cannot be lowered below what was paid.
  Status: not paid / partly paid / paid.
- A purchase can be deleted only while it has no payments; its ledger row goes with it.
- Everything is audited; CSV of purchases and of the ledger needs `finance.export`.

## Who sees what

`finance.view` lists budgets and purchases of the departments its scope reaches. The default
Principal role (institution scope) gets view, manage and export; the HOD role (department scope)
gets view and manage. Setting budgets stays with organization admins unless given `finance.budget`.
