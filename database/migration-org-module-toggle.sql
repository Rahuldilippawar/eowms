-- ============================================================
-- Migration: Organization-निहाय Module Visibility Control
-- (Super Admin ठरवतो — कोणत्या Organization ला कोणते Modules
-- दिसतील. Default — Row नसेल तर Module आपोआप Enabled धरला जातो,
-- जेणेकरून जुन्या Organizations चं काहीही अचानक गायब होणार नाही.)
-- ============================================================
CREATE TABLE organization_module_settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  module_key VARCHAR(50) NOT NULL,
  is_enabled TINYINT(1) DEFAULT 1,
  updated_by INT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_org_module (organization_id, module_key)
) ENGINE=InnoDB;
