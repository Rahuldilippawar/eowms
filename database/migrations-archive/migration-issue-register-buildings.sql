-- ============================================================
-- Migration: Multi-Department Issue Register + Building-wise Rooms
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

-- Super Admin decides which departments get the "देखभाल नोंदवही (Issue Register)" tab
ALTER TABLE departments ADD COLUMN has_issue_register TINYINT(1) DEFAULT 0 AFTER hod_id;

-- Turn it on for Maintenance by default (matches how it already worked)
UPDATE departments SET has_issue_register = 1 WHERE code = 'MTN';

-- Buildings — Super Admin sets these up; rooms belong to a building
CREATE TABLE buildings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NULL,
  name VARCHAR(255) NOT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Link existing rooms/locations to a building (nullable at first for backward compatibility)
ALTER TABLE maintenance_locations ADD COLUMN building_id INT NULL AFTER campus_id;
ALTER TABLE maintenance_locations ADD FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE SET NULL;
