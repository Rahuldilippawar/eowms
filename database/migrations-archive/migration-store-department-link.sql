-- ============================================================
-- Migration: Store Items ला Department-Link जोडणे
-- (भोजनालय/इतर कुठल्याही विभागासाठी — स्वतंत्र Live Stock Dashboard)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE store_items ADD COLUMN department_id INT NULL AFTER category;
ALTER TABLE store_items ADD CONSTRAINT fk_store_items_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL;
