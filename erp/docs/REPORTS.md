# Reports (Phase 10b)

## Reports hub (`/reports`)

One page listing every report of the app, grouped (people, work, money, assets and store,
organization). It shows only the reports the person can open (the same permission and module rules
as the menu), so it is in everyone's menu.

## Exports: CSV, Excel, PDF

- **CSV**: the reports and registers that had a "Download CSV" keep it. The files are UTF-8 with a
  byte-order mark and spreadsheet formulas neutralised, so they open directly in **Excel** with
  Marathi text intact. No separate .xlsx format is produced.
- **PDF / paper**: the menus and top bar are left out of every page when printing, and the report
  pages below have a **Print / PDF** button (the browser's "Save as PDF" makes the PDF).

## Work report, printable (`/work/print`)

The old app's "कामाचा अहवाल" print. For a period (default the last 7 days), optionally one status:

- a per-department summary: entries, completed, in progress, hours, share completed;
- every entry by date: employee and department, title and description, the team, material used,
  status and whether it was reviewed.

Only entries of employees within the person's `daily_work.view` scope; at most 2,000 entries (a note
says so when the period has more).

## Campus comparison (`/reports/campuses`)

The old app's campus compare, with more figures. One column per campus, one row per figure:
departments, staff, students, present today and the attendance share, work entries in the period
and the share completed, open work requests. The best share in a row is highlighted.

- Needs `institutions.view` **organization- or campus-wide** (a department-wide grant sees no campus
  figures, since they would cover other departments). Campus-wide sees its own campuses.
- Figures of a module the organization switched off are left out.
- Period defaults to this month; attendance and open requests are as of today. CSV download.
