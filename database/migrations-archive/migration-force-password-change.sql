-- ============================================================
-- Migration: पहिल्या Login ला जबरदस्ती नवीन पासवर्ड
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) DEFAULT 0 AFTER password;
