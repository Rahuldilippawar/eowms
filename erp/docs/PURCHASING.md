# Purchasing (Phase 9a)

Quotation → purchase order → goods received → vendor invoice → payments. All rules run on the
server; the module can be switched off per organization (`purchasing`). Amounts are always
computed by the server from quantity × rate and GST %.

## Who does what

| | Sees | Changes |
|---|---|---|
| `purchasing.view` | vendors; quotations and orders of the departments in its scope | — |
| `purchasing.manage` | (with view) | vendors; quotations, orders, invoices and payments of the departments in its scope |
| `store.manage` | orders (to receive against) | goods received |

The principal's default role has view and manage; the HOD role has view.

## Vendors

Name (unique), contact person, phone, e-mail, GSTIN (15 characters, stored in capitals), address.
A vendor no longer used is marked inactive and cannot be chosen for new quotations or orders.

## Quotations

A department's quotation from a vendor: date, valid until, the vendor's reference, and lines. A line
is a store item (its unit comes along) or a description (a service such as delivery), with quantity,
rate and GST %. A quotation is **accepted** — which makes a draft order with the same lines — or
**rejected**.

## Purchase orders

- Made from a quotation or directly. A **draft** can be changed freely.
- **Issuing** gives the number `PO/26-27/001` (gap-free per organization and financial year of the
  order date) and fixes the lines.
- When the order is more than what is left in the department budget, the order page says so; it can
  still be issued (as with purchases in Phase 7a).
- A draft or issued order with nothing received can be **cancelled** (with a reason in the audit).

## Goods received (GRN)

- Against an issued order: date (not before the order date, not in the future) and the quantity of
  each line received now — never more than is still to come on the line. Each receipt is numbered
  `GRN/26-27/001`.
- Lines that are store items add to the stock at the order rate.
- The order becomes **partly received**, then **received** when every line has come.

## Invoices and payments

- The vendor's invoice: their number (unique per vendor), date, goods with GST, and other charges
  (transport…). Goods billed can never exceed the value of goods received and not yet billed.
- The invoice total is charged to the department budget, in the financial year of the invoice
  date (the Phase 7a ledger, source `purchase_invoice`).
- Payments (advance / part / final, mode) can never exceed what is due. An invoice with no payments
  can be deleted; its budget charge goes with it.
- Everything is audited.
