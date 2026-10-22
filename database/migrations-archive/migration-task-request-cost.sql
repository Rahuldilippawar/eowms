-- ============================================================
-- Migration: Task/कामाची विनंती — खर्च Tracking + Advance Report
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE tasks ADD COLUMN cost_incurred DECIMAL(10,2) NULL AFTER completion_remarks;
ALTER TABLE work_requests ADD COLUMN cost_incurred DECIMAL(10,2) NULL AFTER work_description;
ALTER TABLE work_requests ADD COLUMN completion_remarks TEXT NULL AFTER cost_incurred;
