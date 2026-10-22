-- ============================================================
-- Migration: Automated Scheduled Backup — नोंद ठेवण्यासाठी
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE scheduled_backups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  filename VARCHAR(255) NOT NULL,
  file_size_kb INT NULL,
  status ENUM('success','failed') NOT NULL DEFAULT 'success',
  error_message TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB;
