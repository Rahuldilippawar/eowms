-- ============================================================
-- Migration: देखभाल नोंदवही — जागा-निहाय इतिहास (Maintenance Log)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE maintenance_locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  campus_id INT NULL,
  name VARCHAR(255) NOT NULL,
  location_code VARCHAR(50) NOT NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_org_location_code (organization_id, location_code)
) ENGINE=InnoDB;

CREATE TABLE maintenance_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  location_id INT NOT NULL,
  department_id INT NOT NULL,
  item_description VARCHAR(255) NOT NULL,
  work_type ENUM('बसवले','दुरुस्त केले','बदलले','तपासणी') NOT NULL DEFAULT 'दुरुस्त केले',
  work_date DATE NOT NULL,
  cost DECIMAL(10,2) NULL,
  remarks TEXT NULL,
  performed_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (location_id) REFERENCES maintenance_locations(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_location (location_id)
) ENGINE=InnoDB;
