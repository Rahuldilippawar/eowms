-- ============================================================
-- Migration: Dead Stock Item Master (केंद्रीकृत वस्तू यादी)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE dead_stock_item_master (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  item_name VARCHAR(255) NOT NULL,
  category VARCHAR(100) NULL,
  default_unit VARCHAR(50) NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_org_item (organization_id, item_name)
) ENGINE=InnoDB;

-- (ऐच्छिक) आधीच्या मुक्त-टायपिंग नोंदींमधून वेगळी नावं आपोआप master list मध्ये टाका:
INSERT IGNORE INTO dead_stock_item_master (organization_id, item_name, category)
SELECT DISTINCT organization_id, item_name, category FROM dead_stock_items;

-- dead_stock_items मध्ये आता कुठला master-item निवडला हे जोडण्यासाठी:
ALTER TABLE dead_stock_items ADD COLUMN item_master_id INT NULL AFTER department_id;
ALTER TABLE dead_stock_items ADD FOREIGN KEY (item_master_id) REFERENCES dead_stock_item_master(id) ON DELETE SET NULL;
