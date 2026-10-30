# Global search and the command palette (Phase 10a)

- **Ctrl+K** (⌘K on a Mac), or the search button in the top bar, opens the palette.
- Typing filters the pages of the menu the person can open (same rules as the sidebar). From two
  letters on, records are searched too: up to 5 per group.
- Arrow keys move, Enter opens, Esc closes.

## What is searched

| Group | Matches | Who sees a result |
|---|---|---|
| Employees | name, employee code | `employees.view`, within its scope |
| Students | name, student code, admission no., roll no. | `students.view`, within its scope (opens the student list filtered by the name) |
| Letters | subject, file / outward / reference no. | the letters the person can open (their own, sent to them, or within `letters.view`) |
| Vehicles | vehicle number, model | `vehicles.view` within its scope, and drivers their own vehicles |
| Renewals | item name, reference no. | `renewals.view`, within its departments |
| Store items | item name | `store.view` |
| IT assets | asset tag, serial no. | `it.view` / `it.manage`, and lab assistants their own labs |

Each group reuses its module's own visibility rules, so a search never shows what the module's pages
would not, and a module the organization switched off is not searched. Searching is limited to 60
requests a minute per person.
