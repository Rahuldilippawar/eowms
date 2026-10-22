-- ============================================================
-- Migration: विभाग-निहाय Dead Stock Register (टिकाऊ मालमत्ता नोंदवही)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE dead_stock_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  department_id INT NOT NULL,
  item_code VARCHAR(50) NOT NULL UNIQUE,
  item_name VARCHAR(255) NOT NULL,
  category VARCHAR(100) NULL,
  quantity INT NOT NULL DEFAULT 1,
  unit VARCHAR(50) NULL,
  purchase_date DATE NULL,
  purchase_cost DECIMAL(12,2) NULL,
  `condition` ENUM('चांगली','दुरुस्तीयोग्य','खराब','भंगार') NOT NULL DEFAULT 'चांगली',
  location VARCHAR(255) NULL,
  remarks TEXT NULL,
  added_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_dept (department_id)
) ENGINE=InnoDB;
