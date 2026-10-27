# Store (Phase 9a)

The old app's store item list, turned into a real stock ledger. All rules run on the server; the
module can be switched off per organization (`store`).

## Who does what

| | Sees | Changes |
|---|---|---|
| `store.view` | items, stock, ledgers, what was issued | — |
| `store.manage` | (with view) | items; stock in, out and corrections; goods received against purchase orders |

The store is organization-wide (the scope of these permissions is not narrowed by department). The
principal's default role has `store.view`.

## Items

Name (unique in the organization), category, unit (kg, piece, bag…), the department that mainly uses
it (or shared), and a reorder level. An item no longer used is marked "not in use": it stays in the
ledger and in old work entries, but cannot be chosen again.

## Stock ledger

- Every movement is a row with a signed quantity; an item's stock is their sum.
  - **In** (receive): bought or donated stock, with an optional rate per unit.
  - **Out** (issue): given to a department and/or a person.
  - **Correction** (adjust, + or −): after a count, with a reason (required).
- Nothing can take the stock below zero; two people issuing the last units at once cannot both
  succeed (the item is locked while a movement is written).
- Goods received against a purchase order come in at the order's rate (see
  [`PURCHASING.md`](PURCHASING.md)).
- When an issue or correction takes an item to its reorder level, the other `store.manage` holders
  (organization-wide) are notified once; the next reminder comes only after the stock went above
  the level again.
- The item page shows the ledger with the stock after each row. The stock list (CSV) and "issued
  from the store" (by department and item, for dates) are reports.

## Material used in daily work

A daily work entry can name the store material used (item and quantity; up to 20), as in the old
app. This records usage for the **material usage report** (by department and item, and the entries
behind them, for the employees in the reader's `daily_work.view` scope). It does not take stock out
of the store: the store issues material separately, so a work entry is never refused for lack of
stock.
