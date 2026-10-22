-- ============================================================
-- Migration: पत्रव्यवहार — Dispatch Mode Tracking
-- ============================================================
ALTER TABLE letters ADD COLUMN dispatch_mode ENUM('Post','Courier','Speed Post','Email','Hand Delivery','Other') NULL AFTER outward_no;
ALTER TABLE letters ADD COLUMN dispatch_tracking_no VARCHAR(100) NULL AFTER dispatch_mode;
ALTER TABLE letters ADD COLUMN dispatch_date DATE NULL AFTER dispatch_tracking_no;
