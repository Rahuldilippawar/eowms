-- ============================================================
-- Migration: Welcome Music — Super Admin Panel वरून Upload करण्यासाठी
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE organizations ADD COLUMN welcome_audio_path VARCHAR(255) NULL AFTER logo_path;
