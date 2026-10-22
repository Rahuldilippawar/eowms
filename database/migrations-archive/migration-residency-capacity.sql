-- ============================================================
-- Migration: रहिवासी विभाग — Room Capacity + Family Member
-- Employee-Link सुविधा
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE maintenance_locations ADD COLUMN capacity INT NULL AFTER location_code;
