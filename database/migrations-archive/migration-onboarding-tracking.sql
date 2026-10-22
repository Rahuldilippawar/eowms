-- ============================================================
-- Migration: Onboarding Tour Tracking
-- ============================================================
ALTER TABLE users ADD COLUMN onboarding_seen TINYINT(1) DEFAULT 0 AFTER must_change_password;
