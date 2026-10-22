-- ============================================================
-- Migration: नूतनीकरण Module — Advance Features
-- (Escalating Reminder, History, Vendor Contact)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE renewal_items
  ADD COLUMN responsible_user_id INT NULL AFTER department_id,
  ADD COLUMN vendor_contact VARCHAR(100) NULL AFTER vendor_name,
  ADD FOREIGN KEY (responsible_user_id) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE renewal_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  renewal_item_id INT NOT NULL,
  old_expiry_date DATE NOT NULL,
  old_issued_date DATE NULL,
  old_renewal_cost DECIMAL(12,2) NULL,
  old_vendor_name VARCHAR(255) NULL,
  old_reference_no VARCHAR(100) NULL,
  renewed_by INT NULL,
  renewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (renewal_item_id) REFERENCES renewal_items(id) ON DELETE CASCADE,
  FOREIGN KEY (renewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
