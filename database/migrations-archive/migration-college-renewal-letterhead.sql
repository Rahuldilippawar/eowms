-- ============================================================
-- Migration: College — NAAC/UGC Renewal Dates + College-wise Letterhead
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE campuses
  ADD COLUMN naac_valid_until DATE NULL AFTER naac_grade,
  ADD COLUMN ugc_valid_until DATE NULL AFTER naac_valid_until,
  ADD COLUMN letterhead_logo_path VARCHAR(255) NULL AFTER established_year;
