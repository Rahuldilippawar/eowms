-- ============================================================
-- Migration: नूतनीकरण — Government Regulatory Body Tracking
-- (CBSE, State Board, AICTE, DTE, MSBTE, University, UGC, NAAC, इ.)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE renewal_items
  ADD COLUMN regulatory_body ENUM('CBSE','State Board','AICTE','DTE Maharashtra','MSBTE','University','UGC','NAAC','RTE','Other Government','Not Applicable') NULL AFTER item_type;
