# CCTV (Phase 9d)

The old app's `cctv/` module. All rules run on the server; the module can be switched off per
organization (`cctv`).

## Who does what

| | Sees | Changes |
|---|---|---|
| `cctv.view` | recorders, cameras, checks, report | — |
| `cctv.check` (e.g. security staff) | (the same) | records the daily check |
| `cctv.manage` | (the same) | recorders, cameras, checks, import |

The principal's default role has `cctv.view`.

## Recorders and cameras

- A recorder (DVR / NVR): name (unique), location, department, how many cameras it takes, storage
  (TB), installed on. A recorder cannot take more cameras in use than its channels, and its
  channels cannot be cut below the cameras in use.
- A camera: name (unique within its recorder), location, type (dome / bullet / PTZ / other). A camera
  no longer used is marked so and leaves the daily check.

## The daily check

- Once a day per recorder: storage used (%), days of footage kept, backup taken (and where), remarks,
  and each camera in use as working / not working / needs attention (with what is wrong).
- The check opens with every camera as working; saving the same recorder and day again replaces it.
  Days in the future are refused; an earlier day can be filled in late.
- When a check records cameras **not working**, the `cctv.manage` holders (organization-wide) are
  notified once for that check.

## Overview and report

- Overview: recorders checked today, camera counts from each recorder's latest check, and each
  recorder's latest check (storage, footage, backup). A recorder not checked for more than **2 days**
  (or never) is shown in red.
- Report for the last 7 or 30 days: days each recorder was checked (and the share of days), backups,
  the fullest storage, and camera results.
- CSV import: `dvr_name, dvr_location, channel_capacity, storage_capacity_tb, installed_date,
  camera_name, camera_location, camera_type`, one row per camera; recorders that do not exist are
  created; every row checked first, then all rows or none.
