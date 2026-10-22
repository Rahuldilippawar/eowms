-- ============================================================
-- Migration: भांडार Material-Master (Daily-Work च्या "वापरलेलं
-- साहित्य" Feature साठी आवश्यक — Purchase/Store चं Formal
-- Workflow काढलं, फक्त ही Simple Item-List ठेवली)
-- ============================================================

CREATE TABLE store_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  item_name VARCHAR(255) NOT NULL,
  category VARCHAR(100) NULL,
  unit VARCHAR(50) NOT NULL,
  reorder_level DECIMAL(10,2) DEFAULT 0,
  is_active TINYINT(1) DEFAULT 1,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO permissions (permission_key, label, label_local, sort_order) VALUES
('manage_store', 'Manage the Material-Master list used by Daily-Work', 'भांडार Material-यादी व्यवस्थापणे', 19);

INSERT INTO role_permissions (role_key, permission_key)
SELECT role_key, 'manage_store' FROM role_definitions WHERE category = 'management';
