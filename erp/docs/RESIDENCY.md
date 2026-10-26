# Staff housing (Phase 8d)

The old app's `residency/` module: staff quarters on campus. All rules run on the server; the
module can be switched off per organization (`residency`).

Housing uses the buildings and rooms of the maintenance register (Phase 8a): a room is a
maintenance location marked **residential**, with an optional capacity (people).

## Who does what

| | Sees | Changes |
|---|---|---|
| Every staff member | their own room, family, bill shares and requests (**My residence**) | asks for a room, cancels their open request |
| `residency.view` | rooms, requests, bills, directory, figures | — |
| `residency.manage` | (with view) | people in rooms, items, inspections, bills, guests, requests, import |

The principal's default role has `residency.view`; the estate office gets `residency.manage`.

## People in rooms

- A **staff** occupant is an employee with an account; a staff member lives in one room at a time.
  A **family** occupant (name, relation, age) lives with a staff occupant of the same room.
- A room with a capacity takes no more people than that, family included.
- **Moving out** a staff member moves their family out with them (date, remark).
- **Moving room**: the household (staff member and family) moves together; the new room must have
  space for all of them. Each move is kept as a room change.
- Imported staff rows without a matching e-mail are kept **not linked**; the directory's
  "Not linked" tab links each one to an account.

## Requests

- A staff member without a room asks for one: preferred building, people who will live, reason.
  One open request at a time; the `residency.manage` holders are notified.
- The estate office **allots a room** (which moves the person in), puts the request on the
  **waiting list**, or **rejects** it. The requester is notified each time.

## Bills

- One bill per room and month: rent, electricity, water. It is split equally among the room's
  staff occupants (not their families); the last share takes the rounding (1501 between two →
  750.50 + 750.50). Each staff member is notified of their share.
- Shares are marked paid one by one. The bills page shows a month across rooms: billed and
  not yet paid.

## Items, inspections, guests

- Items in a room: name, quantity, belongs to the institution or is personal.
- Inspections: date, condition (excellent / good / fair / poor), remarks, who inspected.
- Guests: name, contact, relation, purpose, staying with, arrival and expected departure; marked
  as left when they go.

## Directory and figures

- **Directory**: active staff who live on campus (room, since, family) and those who do not.
- **Figures**: rooms in use (%), people living, staff housed, open requests, guests staying, the
  average stay of those who moved out, and occupancy by building.

## Import

CSV columns `building_name, room_name, occupant_type, name, staff_email, relation, moved_in_date`,
one row per person (`occupant_type` staff / family; dates YYYY-MM-DD or DD/MM/YYYY). Missing
buildings and rooms are created (residential, codes `RES-{batch}-{nnn}`); a family row belongs to
the first staff row of the same room. Every row is checked first, then all rows or none.

Each import is kept in the history and can be **undone** (people, rooms and buildings it created
are removed) as long as no bill was made for those people.
