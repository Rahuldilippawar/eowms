# Dead stock and the issue register (Phase 9b)

The old app's `deadstock/` and `allocations/` modules. All rules run on the server; the module can be
switched off per organization (`assets`).

## Who does what

| | Dead stock | Item list | Issue register |
|---|---|---|---|
| `assets.view` | the departments in its scope | sees | sees, only when held organization-wide |
| `assets.manage` | records, edits and deletes in its scope | changes and imports, only organization-wide | gives, takes back, transfers, sets up types and items, imports — only organization-wide |
| `assets.export` | CSV | — | CSV |
| Everyone | — | — | **Items with me**: what they hold now and what they returned |

The issue register belongs to the whole organization, so a department-scoped permission does not
open it. The principal's default role has view, manage and export; the HOD role has view and manage
(dead stock of their department).

## Item list (item master)

The organization's one list of asset items — name (unique), category, usual unit — so that every
department names an item the same way. Departments pick from it; items no longer used are marked so
and cannot be picked. CSV import: `item_name, category, default_unit`, every row checked first, then
all rows or none.

## Dead stock register

- An entry: department, item from the list, quantity, unit, purchase date and cost, condition
  (good / can be repaired / bad / scrap), location, remarks.
- Each entry gets a code `DS-{department code}-0001`, numbered per department without gaps.
- **New purchase** (cost required) is charged to the department budget in the financial year of its
  purchase date (Phase 7a ledger, source `dead_stock`); **already had it** is only recorded.
  Editing updates the charge; switching to "already had it" or deleting removes it.
- Report: entries, quantity and value by department, category and condition; CSV of the register.

## Issue register (allocations)

- **Types**: e.g. Laptop (kept) or Room keys (borrowed, given with a return time). A type can ask
  extra details when an item is given — text, number, date, choice (with its choices) or yes / no,
  each optionally required. A detail keeps its key when renamed, so values already saved stay.
- **Items** of each type (name unique within the type, usual place). An item still with someone
  cannot be marked not in use.
- **Give**: an item to an active staff member, with purpose, optional return time and the type's
  details. An item is with one person at a time.
- **Take back** (reason) and **transfer** (closes one allocation and opens the next for the new
  person with the same details; both stay in the history).
- Views: with people now, borrowed, late, all with returns; CSV; report (held, late, free items, given
  in 30 days, by type, by holder).
- The person given an item is notified. Every day at 09:10 India time, a late item reminds its holder
  and whoever gave it (once a day each, until it comes back).
- CSV import of types and items: `type_name, item_name, default_location, is_loan`; missing types are
  created (`is_loan` yes / no for a new type).
