-- ============================================================
-- Migration: वाढदिवस साजरा (Birthday Celebration) — Photo + DOB
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

-- Staff: date_of_birth already exists; add photo
ALTER TABLE users ADD COLUMN photo_path VARCHAR(255) NULL AFTER email;

-- Hostel Students: add both date_of_birth and photo
ALTER TABLE hostel_students ADD COLUMN date_of_birth DATE NULL AFTER gender;
ALTER TABLE hostel_students ADD COLUMN photo_path VARCHAR(255) NULL AFTER date_of_birth;
