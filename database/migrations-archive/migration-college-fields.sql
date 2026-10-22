-- ============================================================
-- Migration: Campus/College — Advance Fields (Principal, Affiliation,
-- NAAC, Established Year, इ.)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE campuses
  ADD COLUMN college_type ENUM('Arts','Science','Commerce','Engineering','Pharmacy','Education','Law','Medical','Polytechnic','Other') NULL AFTER code,
  ADD COLUMN principal_name VARCHAR(255) NULL AFTER college_type,
  ADD COLUMN affiliated_university VARCHAR(255) NULL AFTER principal_name,
  ADD COLUMN ugc_recognition VARCHAR(150) NULL AFTER affiliated_university,
  ADD COLUMN naac_grade VARCHAR(20) NULL AFTER ugc_recognition,
  ADD COLUMN established_year YEAR NULL AFTER naac_grade;
