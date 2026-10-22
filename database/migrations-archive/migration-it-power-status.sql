-- ============================================================
-- Migration: IT Assets — Live Power ON/OFF Status
-- (Condition-Status 'working/faulty' पासून वेगळं — हे "आत्ता चालू आहे
-- का बंद आहे" हे रोजचं, Quick Status आहे)
-- ============================================================

ALTER TABLE it_assets ADD COLUMN power_status ENUM('on','off') DEFAULT 'off' AFTER status;
ALTER TABLE it_assets ADD COLUMN power_status_updated_at TIMESTAMP NULL AFTER power_status;
ALTER TABLE it_assets ADD COLUMN power_status_updated_by INT NULL AFTER power_status_updated_at;
ALTER TABLE it_assets ADD CONSTRAINT fk_it_assets_power_updater FOREIGN KEY (power_status_updated_by) REFERENCES users(id) ON DELETE SET NULL;
