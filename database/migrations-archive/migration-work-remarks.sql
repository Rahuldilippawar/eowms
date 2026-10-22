-- ============================================================
-- Migration: Add "remarks" to work_records
-- Used when staff updates a pending/in-progress work item —
-- either marking it complete, or explaining why it's still pending.
-- Run this ONLY if your database was created before this update.
-- ============================================================

ALTER TABLE work_records ADD COLUMN remarks TEXT NULL AFTER status;
