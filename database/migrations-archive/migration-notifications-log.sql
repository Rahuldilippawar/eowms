-- ============================================================
-- Migration: Notifications + Activity Log
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  user_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  message TEXT NULL,
  link VARCHAR(255) NULL,
  is_read TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_user_read (user_id, is_read)
) ENGINE=InnoDB;

CREATE TABLE activity_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NULL,
  user_id INT NULL,
  actor_name VARCHAR(255) NULL,
  action VARCHAR(120) NOT NULL,
  entity_type VARCHAR(60) NULL,
  entity_id INT NULL,
  details TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_org_created (organization_id, created_at)
) ENGINE=InnoDB;
