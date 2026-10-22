-- ============================================================
-- Migration: Add organization logo support
-- Run this in phpMyAdmin (SQL tab) ONLY IF your `eowms` database
-- was created BEFORE this update. Fresh installs using the main
-- schema.sql already include this column — skip this file then.
-- ============================================================

ALTER TABLE organizations ADD COLUMN logo_path VARCHAR(255) NULL AFTER type;
