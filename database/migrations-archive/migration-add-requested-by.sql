-- ============================================================
-- Migration: Add "requested_by" to work_records
-- This is the person/department who ASKED for the work to be
-- done (e.g. "Principal Office", "Hostel Warden", a teacher's name)
-- — separate from the employee who actually performed the work.
-- Run this ONLY if your database was created before this update.
-- ============================================================

USE eowms;

ALTER TABLE work_records ADD COLUMN requested_by VARCHAR(255) NULL AFTER department_id;
