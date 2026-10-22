-- ============================================================
-- Migration: खोल्यांना मजला (Floor) नोंदवणे — Building Layout साठी
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE maintenance_locations ADD COLUMN floor INT NOT NULL DEFAULT 0 AFTER building_id;
-- 0 = तळमजला (Ground Floor), 1 = पहिला मजला, 2 = दुसरा मजला, इ.
