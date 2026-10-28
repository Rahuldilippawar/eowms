# IT department (Phase 9c)

The old app's `it/` module. All rules run on the server; the module can be switched off per
organization (`it`). General IT requests (internet, software, accounts…) are work requests with the
IT category (Phase 8b), as the old app had already moved them there.

## Who does what

| | Sees | Changes |
|---|---|---|
| Everyone signed in | an asset found by its tag; their own reports | reports a problem with an asset |
| A lab's **assistant** | their own labs and assets | adds and edits assets of their labs; status and power |
| `it.view` | every lab, asset and problem | — |
| `it.manage` | (all) | labs and their assistant; any asset; transfers; starting and fixing problems; import |

Lab assistants need no IT permission: the menu shows the IT department to anyone who assists an
active lab (`it_lab_assistant` on `/auth/me`). The principal's default role has `it.view`.

## Labs and assets

- A lab: name (unique), location, seats, department, assistant.
- An asset: lab, kind (computer, monitor, keyboard, mouse, CPU, UPS, smart board, projector, printer,
  other), workstation, brand, model, serial no., purchase date and cost, warranty until, OS / licence
  and licence until, remarks.
- Each asset gets a tag by kind and year — `PC-26-0001`, `MON-26-0001`, `SB-26-0001` — numbered
  without gaps per organization; it is printed on the asset.
- Status: working / faulty / being repaired / scrapped; power on / off (with when and who), used for
  smart boards and projectors.
- **Moving** an asset to another lab is recorded (from, to, who, when); its open problems move with it.

## Problems (repair tickets)

1. Anyone types the tag printed on the asset and says what is wrong. A working asset turns
   **faulty**; `it.manage` holders (organization-wide) and the lab's assistant are notified. A
   scrapped asset takes no reports.
2. IT **starts work**: the asset is **being repaired**.
3. IT marks it **fixed** with what was done; when no other problem is open the asset is **working**
   again (unless it was scrapped meanwhile), and the person who reported it is notified.

## Overview and reports

- Overview: assets by status, open problems, smart boards (on / with a problem), each lab's counts,
  and assets whose warranty or licence ends within 30 days.
- Smart board report: every smart board with status, power and problems.
- Assets CSV. CSV import: `lab_name, location, capacity, workstation_no, asset_type, brand, model,
  serial_no, purchase_date, purchase_cost, warranty_expiry, os_license, license_expiry`; labs that
  do not exist are created; every row checked first, then all rows or none.
