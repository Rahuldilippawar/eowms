-- ============================================================
-- Migration: कर्मचारी पगार + Joining/Leave Date Tracking
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE users ADD COLUMN salary DECIMAL(10,2) NULL AFTER designation;
ALTER TABLE users ADD COLUMN joining_date DATE NULL AFTER salary;
ALTER TABLE users ADD COLUMN left_date DATE NULL AFTER joining_date;

-- Existing employees: default their joining_date to when their record was created
UPDATE users SET joining_date = DATE(created_at) WHERE joining_date IS NULL;
