-- ============================================================
-- Migration: IT विभाग — Labs, Assets, Repair Tickets
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE it_labs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  department_id INT NULL,
  lab_name VARCHAR(255) NOT NULL,
  location VARCHAR(255) NULL,
  capacity INT NULL,
  assistant_id INT NULL COMMENT 'या Lab ला नेमलेला Assistant — तोच Data भरेल',
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  FOREIGN KEY (assistant_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE it_assets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  lab_id INT NOT NULL,
  asset_tag VARCHAR(50) NOT NULL UNIQUE COMMENT 'QR/Barcode साठी अद्वितीय क्रमांक',
  asset_type ENUM('computer','monitor','keyboard','mouse','cpu','ups','smartboard','projector','printer','other') NOT NULL,
  brand VARCHAR(100) NULL,
  model VARCHAR(100) NULL,
  serial_no VARCHAR(150) NULL,
  purchase_date DATE NULL,
  purchase_cost DECIMAL(12,2) NULL,
  warranty_expiry DATE NULL,
  os_license VARCHAR(150) NULL COMMENT 'फक्त Computer साठी — कोणतं OS/Office License',
  license_expiry DATE NULL,
  status ENUM('working','faulty','under_repair','scrapped') DEFAULT 'working',
  remarks VARCHAR(255) NULL,
  added_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (lab_id) REFERENCES it_labs(id) ON DELETE CASCADE,
  FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE it_asset_transfers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  asset_id INT NOT NULL,
  from_lab_id INT NULL,
  to_lab_id INT NOT NULL,
  transferred_by INT NULL,
  remarks VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (asset_id) REFERENCES it_assets(id) ON DELETE CASCADE,
  FOREIGN KEY (from_lab_id) REFERENCES it_labs(id) ON DELETE SET NULL,
  FOREIGN KEY (to_lab_id) REFERENCES it_labs(id) ON DELETE CASCADE,
  FOREIGN KEY (transferred_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE it_repair_tickets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  asset_id INT NOT NULL,
  lab_id INT NOT NULL,
  issue_description TEXT NOT NULL,
  status ENUM('pending','in_progress','resolved') DEFAULT 'pending',
  resolution_notes TEXT NULL,
  reported_by INT NOT NULL,
  resolved_by INT NULL,
  resolved_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (asset_id) REFERENCES it_assets(id) ON DELETE CASCADE,
  FOREIGN KEY (lab_id) REFERENCES it_labs(id) ON DELETE CASCADE,
  FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_asset_status (asset_id, status)
) ENGINE=InnoDB;

INSERT INTO permissions (permission_key, label, label_local, sort_order) VALUES
('manage_it', 'Access the IT department module', 'IT विभाग पाहणे/व्यवस्थापणे', 21);

INSERT INTO role_permissions (role_key, permission_key)
SELECT role_key, 'manage_it' FROM role_definitions WHERE category = 'management';
