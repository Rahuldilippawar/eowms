-- ============================================================
-- Migration: Failed Login Lockout (Brute-force संरक्षण)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE users ADD COLUMN failed_login_attempts INT DEFAULT 0 AFTER password;
ALTER TABLE users ADD COLUMN locked_until DATETIME NULL AFTER failed_login_attempts;
