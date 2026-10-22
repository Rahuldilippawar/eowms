-- ============================================================
-- Migration: Site Maintenance Mode
-- Super Admin एका Click वर संपूर्ण Site "Under Maintenance" करू शकेल —
-- Super Admin सोडून सगळ्यांना एक सूचना-पान दिसेल (आवाजासह).
-- ============================================================

ALTER TABLE organizations ADD COLUMN maintenance_mode TINYINT(1) DEFAULT 0 AFTER is_active;
ALTER TABLE organizations ADD COLUMN maintenance_message TEXT NULL AFTER maintenance_mode;
ALTER TABLE organizations ADD COLUMN maintenance_started_at TIMESTAMP NULL AFTER maintenance_message;
