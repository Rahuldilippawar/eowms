-- ============================================================
-- Migration: College Type मध्ये "School" प्रकार जोडणे
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE campuses MODIFY COLUMN college_type
  ENUM('Primary School','Secondary School','Junior College','Arts','Science','Commerce','Engineering','Pharmacy','Education','Law','Medical','Polytechnic','Other') NULL;
