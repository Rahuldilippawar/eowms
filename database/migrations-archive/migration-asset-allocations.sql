-- ============================================================
-- Migration: Asset Allocation Registry
-- (कोणाला काय Allot केलं — Xerox, PC, इ. — Super Admin ठरवेल Fields)
-- Current + Full History दोन्ही — unallocated_date NULL असेल तर
-- "अजूनही त्याच्याकडे आहे", असेल तर "आधी होतं, आता नाही".
-- ============================================================

CREATE TABLE asset_allocation_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  name VARCHAR(150) NOT NULL COMMENT 'उदा. Xerox Machine, Computer/PC',
  icon VARCHAR(60) DEFAULT 'fa-solid fa-box',
  is_active TINYINT(1) DEFAULT 1,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE asset_allocation_fields (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type_id INT NOT NULL,
  field_label VARCHAR(150) NOT NULL COMMENT 'उदा. Model No., RAM/Storage',
  field_key VARCHAR(100) NOT NULL COMMENT 'JSON-Key साठी, Auto-Generate होतं',
  field_type ENUM('text','number','dropdown','date','yesno') DEFAULT 'text',
  field_options TEXT NULL COMMENT 'Dropdown असेल तर — Comma-Separated Options',
  is_required TINYINT(1) DEFAULT 0,
  sort_order INT DEFAULT 0,
  FOREIGN KEY (type_id) REFERENCES asset_allocation_types(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE asset_allocations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  type_id INT NOT NULL,
  campus_id INT NOT NULL,
  user_id INT NOT NULL COMMENT 'कोणाला Allot केलं',
  asset_label VARCHAR(150) NULL COMMENT 'उदा. "Xerox #2", "PC-Workstation 5" — Quick Identify साठी',
  custom_data TEXT NULL COMMENT 'Super Admin ने ठरवलेल्या Fields चा Data — JSON-Encoded String',
  allocated_date DATE NOT NULL,
  unallocated_date DATE NULL COMMENT 'NULL = अजूनही याच्याकडे आहे',
  unallocated_reason VARCHAR(255) NULL,
  allocated_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (type_id) REFERENCES asset_allocation_types(id) ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (allocated_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO permissions (permission_key, label, label_local, sort_order) VALUES
('manage_asset_allocations', 'Manage asset allocation types and records', 'Asset Allocation Registry व्यवस्थापन', 38);
