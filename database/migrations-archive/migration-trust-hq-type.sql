-- ============================================================
-- Migration: Campus/College Type मध्ये "Trust/मुख्य कार्यालय" जोडणे
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE campuses MODIFY COLUMN college_type
  ENUM('Trust HQ','Primary School','Secondary School','Junior College','Arts','Science','Commerce','Engineering','Pharmacy','Education','Law','Medical','Polytechnic','Other') NULL;
