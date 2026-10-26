# Correspondence, approvals and signatures (Phase 6)

All rules run on the server; the app only shows the moves the API allows (`actions` on each file).

## A file's life

```
draft ──submit──▶ in_progress ──forward──▶ in_progress … ──sign──▶ completed
                      │   ▲
                return│   │forward
                      ▼   │
                    returned
```

- **Anyone with an employee record** can write a file. It gets a **file number** at once
  (`FILE-26-27-0001`, per organization and financial year) and stays a **draft** — only drafts can be
  changed — until the creator sends it.
- On first sending, the creator's department gives it an **outward number** (`PHY/26-27/0001`). Each
  other department the file reaches gets its own **inward number** once (`ACC/IN/26-27/0001`), however
  often the file comes back. Numbers are gap-free running counters taken under a row lock
  (`number_sequences`), per financial year (April–March, in the organization's timezone).
- **Holder**: exactly one person holds a moving file. They can send it on, send it back (a reason is
  required; it goes to whoever last sent it to them, or the creator), add remarks and attachments,
  and record dispatch.
- **Without a workflow** the sender picks the next person (an active colleague with an employee
  record, never themself); any holder may sign.
- **With a workflow** the file follows its steps in order and only the last step can sign. Sending
  back goes one step back. If nobody can be found for the next step (for example a department without
  a head), the move is refused with a message and the file stays where it is.
- **Sign and close**: the holder confirms their **password** (fingerprint / WebAuthn comes with the
  security phase). For a budget request they can change the approved amount. The creator is told.
  Wrong passwords are refused and audited; the endpoint is rate-limited.
- **Reassign** (`letters.manage` reaching the file): give a stuck file to somebody else.
- **Reminders** (`erp:letter-reminders`, daily 09:00 India time): a file that has not moved for 3
  days reminds its holder and creator; after 7 days the head of its department too. Once per file,
  person and day.

## Who sees a file

The creator, the current holder, anyone who has sent or received it, and anyone whose `letters.view`
scope reaches the file's department (or institution). Everyone else gets 404. Attachments are private
files (PDF, images, Word, Excel up to 10 MB); downloads are audited.

## Approval workflows (generic engine)

`approval_workflows` + `approval_workflow_steps`, with a `module` column so other modules can reuse
them later. Step approvers: head of the file's own department, head of a chosen department, or a
named person. Workflows and letter categories are managed with `letters.manage` at organization
scope; "Add the usual categories" adds the old app's 18 defaults. Changing a workflow's steps affects
files already on the way from their next move.

## Signatures and the QR check

Each signature stores a SHA-256 **content hash** over the file's numbers, subject, text, approved
amount, the attachments' own SHA-256, the signer, the time, the verification code and the previous
signature's hash. The printable page ("Print / PDF" → the browser's *Save as PDF*, which renders
Marathi correctly) carries a QR code to `/verify/signature/{code}`. That public page (no login,
rate-limited) shows the signer, organization, file and outward number, time and method — never the
subject or text — and recomputes the hash: if anything sealed has changed since, it says so.

## Registers

Outward and inward registers per financial year for the departments in the caller's `letters.view`
scope; CSV with `letters.export` (audited).

## Budget requests

A file can be marked as a budget expense (type and amount asked). The approved amount is recorded on
signing; deducting it from the department budget comes with the finance phase (7).
