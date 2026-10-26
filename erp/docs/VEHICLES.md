# Vehicles and the maintenance register (Phase 8a)

All rules run on the server. Both modules can be switched off per organization (`vehicles`,
`maintenance`).

## Vehicles

- A vehicle belongs to a department (scope follows it): number (stored without spaces, upper case,
  unique in the organization), type, model, registration date, chassis / engine numbers, class,
  seats, fuel, driver (an active colleague) and the odometer.
- **Documents** — permit, PUC, insurance, fitness, road tax, green tax, MV tax — are not columns on
  the vehicle: each date is a **renewal item** (`vehicle_id`, `renewal_field`, regulatory body
  "RTO/Transport"). So the renewals register, renewing with history, payments, documents and the
  90 → 0 day reminders all cover them. Clearing a date removes its item; moving the vehicle to
  another department moves its items (and its fuel / service charges); retiring the vehicle stops
  the items' reminders, bringing it back resumes them.
- Retired vehicles keep their history but nothing new can be logged for them.

## Who sees and logs

| | Sees | Logs trips / fuel / services | Edits / deletes entries, decides corrections |
|---|---|---|---|
| The vehicle's **driver** | that vehicle | yes | no — asks for a correction |
| `vehicles.view` | vehicles of the departments in scope | — | — |
| `vehicles.log` | (with view) | vehicles in scope | no — asks for a correction |
| `vehicles.manage` | (with view) | vehicles in scope | yes |

Drivers need no vehicle permission: the menu shows Vehicles to anyone who drives an active vehicle
(`drives_vehicle` on `/auth/me`).

## Trips, fuel, services

- **Trip**: date (not in the future), from, to, optional times, start and end odometer (end ≥
  start); the distance is worked out. The form starts from the vehicle's current odometer.
- **Fuel**: date, odometer, litres, cost, station. The cost is a department spend (finance ledger,
  `source_type = vehicle_fuel`, category "fuel").
- **Service**: date, type, details, cost, odometer, vendor, and the next service by date and / or
  odometer. The cost is a department spend (`vehicle_service`, category "maintenance").
- The odometer only moves forward: the highest reading from trips, fills and services.
- The vehicle page shows total km, litres, km per litre, fuel and service cost, the last six months,
  and a report for any period (print / PDF from the browser, CSV with `vehicles.export`).

## Corrections

The old app's correction requests: whoever can log for the vehicle (driver, `vehicles.log`) asks
for an entry to be fixed or removed, with a reason; one request per entry at a time. The
department head hears. A vehicle manager either **fixes and approves** (the corrected values are
applied and the budget charge follows), **deletes as asked** (the entry and its charge go), or
**rejects**. The requester is told either way. Managers can also edit or delete entries directly;
everything is in the audit log.

## Service-due reminders

`php artisan erp:vehicle-service-due` (daily, 08:10 India time) looks at each active vehicle's
latest service record:

- 7, 3, 1 and 0 days before the next service date (a missed run catches up within 2 days), then
  1 day late and every 7 days after;
- once when the odometer comes within 500 km of the next service reading.

The driver and the department head hear (the vehicle's creator when neither exists). Each
milestone is sent once per service record, so logging the next service starts a fresh set.

## Import

CSV with `vehicle_number, department_code, vehicle_type, model, registration_date, chassis_no,
engine_no, vehicle_class, passenger_capacity, fuel_type, current_odometer, driver_employee_code`
and the seven `*_validity` dates (YYYY-MM-DD or DD/MM/YYYY). Every row is checked first (duplicate
numbers, departments in scope, known employees, dates); then all rows are imported or none.

## Maintenance register

The old app's देखभाल नोंदवही.

- **Buildings** and **locations** (name, unique code, building, floor, capacity, residential, in
  use) are shared by the organization; `maintenance.manage` maintains them. Residential locations
  are what residency (Phase 8d) will allot.
- Only departments **with a register** (switched on in Register settings — maintenance, plumbing,
  electrical, workshop…) log work: location, department, item, kind of work (installed, repaired,
  replaced, inspected), date (not in the future), cost and remarks. `maintenance.log` within its
  department scope.
- The cost is a department spend (`maintenance_log`, category "maintenance"). Payments against it
  go into the finance payment ledger and never exceed the cost; a log with payments cannot be
  deleted or cut below what was paid.
- Each location shows its history, total spent and how often it needed repair in the last 30 days.
- The report gives, for a period (default: this financial year), entries and cost by department
  (with what was paid), the busiest locations and totals by kind of work. CSV of the log with
  `maintenance.export`.

## Permissions

`vehicles.view`, `vehicles.log`, `vehicles.manage`, `vehicles.export`, `maintenance.view`,
`maintenance.log`, `maintenance.manage`, `maintenance.export`, all following the department scope.
The default Principal role gets all of them; HOD gets view and log of both.
