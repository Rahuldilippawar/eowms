# Old app → ERP parity map

The owner's goal: the whole PHP app moves to the Laravel + React ERP, with **every feature of the old
app** plus the features of the MVP prompt. This file lists every module of the old app (≈50 folders,
≈400 pages, ≈190 tables) and where it lands in the ERP. Nothing is dropped silently: a feature is
either built, scheduled in a phase, or listed under "decide" for the owner.

Legend: ✅ in the ERP · 🟡 partly (gaps listed) · ⏳ scheduled (phase) · ❓ owner to decide

## Foundation (done in phases 1–3)

| Old module | Status | Notes / gaps |
|---|---|---|
| Login, logout, forgot / reset password | ✅ | Phase 2 (rate-limited); forced password change ✅ 5A-1 |
| WebAuthn fingerprint login + identity check | ⏳ 11 | Needs HTTPS on the ERP domain |
| Organizations (create, edit, delete, switch) | ✅ | |
| Campuses (= institutions) incl. compare | 🟡 | Campus comparison report → ⏳ 10 |
| Departments incl. dashboard, bulk reassign | ✅ | Dashboard has a department filter; bulk move ✅ 5A-1 |
| Roles / permissions, extra department / campus access | ✅ | Scoped role assignments replace "extra access" |
| Organization module on/off (`organization_module_settings`) | ✅ 5A-1 | Organization settings → Modules |
| Activity log, login activity | ⏳ 10 | Audit log is written since Phase 2; viewer in Phase 10 |
| Account profile (own photo, password) | ✅ 5A-1 | My profile + change password |
| Global search, command palette (Ctrl+K) | ⏳ 10 | |
| Welcome / onboarding tour, About page (contributors) | ⏳ 11 | |
| PWA (manifest, offline page), Web Push / FCM, device tokens | ⏳ 11 | In-app notifications done in Phase 5 |
| Maintenance mode, system update, DB update screen | ⏳ 11 | Replaced by the deploy workflow + migrate screen |
| Backup / restore, scheduled backup, clear data | ⏳ 10 | |
| TV display (notice board screen) | ⏳ 11 | |

## People, attendance, leave (phase 4 → gaps in 5A)

| Old module / page | Status | Gaps to close |
|---|---|---|
| Staff list, create, edit, activate | ✅ 5A-1 | **HR profile fields** (father / mother name, DOB, gender, addresses, mobile / WhatsApp, employment type, second designation, teaching mode, group, marital status, religion / caste, handicap, food preference, Aadhaar, ration card, bank account / IFSC, PF / UAN, ESIC, PT, health scheme, salary, left date, ashram resident and the other community fields) — done; community-specific fields are organization-defined fields. Sensitive ones behind `employees.sensitive`, encrypted and masked |
| Staff photo, documents upload, emergency contact, family members, achievements | ✅ 5A-1 | Private files, permission-checked download |
| Staff CSV import + template | ✅ 5A-1 | Preview, row errors, no partial writes |
| ID card (single + bulk print), ID verification by QR | ✅ 5A-1 | Public verify page shows only name, photo, designation, status |
| Directory (= staff list) | ✅ | Employees list |
| Attendance: self check-in/out, manual entry, regularization | ✅ | |
| Monthly attendance grid, attendance calendar, monthly summary | ✅ 5A-2a | Month grid (codes per day), summary |
| Attendance certificate (print) | ✅ 5A-2a | |
| Movement report | ✅ 5A-2a | In / out register |
| Shift settings per employee (start, end, grace, half day, weekly offs) | ✅ 5A-2a | Overrides the organization rule |
| Holidays calendar + bulk import | ✅ 5A-2a | Leave day count skips holidays and weekly offs |
| Leave types, apply, approvals, my leaves | ✅ | |
| Leave balances (allocate per year, used, remaining) | ✅ 5A-2a | Checked on apply |
| Salary report (print) | ✅ 5A-2a | Pay days × salary; no statutory deductions |
| Biometric devices, PIN mapping, ZKTeco push (`iclock/cdata`, `getrequest`, `devicecmd`) | ✅ 5A-2a | Registered serials only; punch log → attendance |
| Cron: mark absent daily, attendance pattern alert, biometric processing | ✅ 5A-2a | Scheduler commands; punches applied on arrival |
| Cron: birthday / work-anniversary wishes, wish post | 🟡 5A-2a | In-app wishes ✅; colleagues' wish posts → ⏳ 12 (with announcements) |
| Students master + bulk import | ✅ 5A-2b | Roll no, admission no, year of study, academic year, mobile, email; CSV import + export. Student login → ⏳ 14 |

## Work (phase 5 → gaps in 5A)

| Old module / page | Status | Gaps |
|---|---|---|
| Daily work entry, my work, edit, status, report, CSV | ✅ | |
| Daily work team members, before / after photos, requested by, cost | ✅ 5A-2b | Photos private; team mates may see them |
| Custom fields per organization (daily work) | ✅ 5A-1 | Also on employee and student forms |
| Import old work records + template | ✅ 5A-2b | Matched by employee code; all rows or none |
| Daily work print report, material usage report | ⏳ 9 | Material usage needs the store ledger |
| Tasks: assign, my tasks, verify / reopen, report | ✅ | `cost_incurred` ✅ 5A-2b |
| Notifications page, mark all read | ✅ | |
| Cron: overdue tasks | ✅ | `erp:task-reminders` |

## Correspondence and approvals (✅ 6)

| Old module / page | Status | Notes |
|---|---|---|
| Letters: create, view, inward / outward numbers, register + export, print | ✅ 6 | Gap-free numbers per financial year; print → browser PDF with QR |
| Letter categories, seed default categories and workflows | ✅ 6 | 18 default categories; workflows are built in the settings page (no guessed "top management") |
| Workflow templates (create / edit) with steps | ✅ 6 | Generic approval engine (`module` column) for later modules |
| Sign & approve (password / fingerprint), signature verification | 🟡 6 | Password ✅ with SHA-256 seal + public check; fingerprint → ⏳ 11 (WebAuthn) |
| Budget deduction on approval | ✅ 7a | Charged to the department budget when signed |
| Attachments | ✅ 6 | Private storage, audited downloads, sealed by the signature |
| Reassign (super admin), dispatch details, remarks, seen-by-holder | ✅ 6 | |
| Sister-organization recipients | ❓ | Old app could send to a sister organization; ERP files stay inside one organization — owner to decide |
| Cron: overdue letters | ✅ 6 | `erp:letter-reminders` (3-day reminder, 7-day escalation to the department head) |

## Renewals and finance (7)

| Old module / page | Status | Notes |
|---|---|---|
| Department finance: budget allocation, budget check, dashboard, report, export | ✅ 7a | One ledger per source record, financial year of the spend's date |
| Material purchase (GST, transport) + payment ledger | ✅ 7a | Payments never exceed what is due |
| Budget module (overview per department) | ✅ 7a | Budgets page with use bar and spend by source |
| Budget deduction on letter approval | ✅ 7a | Charged on signing |
| Renewals: items, renew, history, attachments, calendar, cost analysis, payment track, import, report, reminders cron | ⏳ 7b | |

## Hostel, residency, transport, maintenance, requests (⏳ 8)

| Old module | Notes |
|---|---|
| Hostels: hostels, rooms, room / bed allocation, students + import, attendance, leave / out-pass, health, fees, alumni, dashboard, bulk import | |
| Residency: buildings, layout, rooms + items, occupants, allotment requests, transfers, inspections, bills (rent / electricity) + shares + report, guests, occupancy analytics, staff directory (resident / non-resident), data import engine with preview / history, link unlinked | |
| Vehicles: vehicles, trips, fuel, maintenance, correction requests, dashboard, import, report, service-due cron | |
| Maintenance log: locations (buildings / rooms), log, location history, payment track, settings, report | |
| Work requests (requests, my requests, actions, comments, views, advance report) + public request form + track request | Public form: rate limit + honeypot (as fixed in the old app) |
| Grievances (complaints) with comments | |
| Tickets escalation cron | |

## Assets, inventory, purchasing (⏳ 9)

Dead stock register + item master + import, asset allocation (issue / return register, types, custom
fields, import, report), IT department (labs, assets, transfers, repair tickets, general requests,
smart board report, import, dashboard), CCTV (DVRs, cameras, status, checkups, import, report), store
items + stock ledger, vendor, quotation → PO → GRN → invoice (MVP prompt).

## Reports, search, audit, backup (⏳ 10)

Reports hub + CSV / Excel / PDF exports, activity / login log viewer, global search, campus comparison,
backup / restore / scheduled backup.

## Remaining old modules (⏳ 12–14, after the MVP phases)

| Old module | Phase |
|---|---|
| Announcements / circulars (departments, read receipts) | 12 |
| Documents repository (upload, download) | 12 |
| Emergency / SOS alerts (trigger, recipients, responses, status) | 12 |
| Messages (staff messages) | 12 |
| Software suggestions (ideas, votes, comments) | 12 |
| Help center (categories, articles, manage) | 12 |
| Guests (registration, pre-registration, mobile lookup) | 12 |
| Room / hall booking (bookable rooms) | 12 |
| Events (events, tasks, budget items, photos, attendance, certificates, recurring-events cron) | 12 |
| SOP compliance (SOPs, steps, department assignment, daily checklist, compliance report, cron) | 12 |
| Gaushala (cattle registry, breeding, health, milk, fodder, sponsorships, register print, report, vaccination-due cron) | 13 |
| Music department (students, enrolment, attendance, exams / grades, instruments + log, sheet library, room booking, events) | 13 |
| Meditation / satsang (events, duty chart, attendance + report, quotes) | 13 |
| Academics: virtual classrooms (staff, students, timetable, attendance, materials, assignments + submissions, exams + marks, Q&A, announcements, teacher feedback) + **student portal** (login, dashboard, classroom, assignments, questions, feedback, notifications) | 14 |

## Data migration

The old data (both organizations) is imported by an importer in Phase 11, table by table, with a dry
run report of anything it cannot map. Until cutover the old app stays live.
