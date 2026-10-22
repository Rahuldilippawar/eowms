-- ============================================================
-- Migration: रहिवासी Advance Import — Batch Tracking
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE residency_import_batches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  filename VARCHAR(255) NULL,
  buildings_created INT DEFAULT 0,
  rooms_created INT DEFAULT 0,
  occupants_created INT DEFAULT 0,
  imported_by INT NULL,
  imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  status ENUM('completed','rolled_back') DEFAULT 'completed',
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (imported_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

ALTER TABLE buildings ADD COLUMN import_batch_id INT NULL AFTER campus_id;
ALTER TABLE maintenance_locations ADD COLUMN import_batch_id INT NULL AFTER building_id;
ALTER TABLE residency_occupants ADD COLUMN import_batch_id INT NULL AFTER location_id;
