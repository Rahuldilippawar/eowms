-- ============================================================
-- Migration: पत्र व्यवहार — 5 नवीन Features
-- (Overdue tracking, CC/Watchers, Read Status, Templates)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

-- Read-status: reset to NULL whenever current_holder_id changes; set when opened
ALTER TABLE letters ADD COLUMN current_holder_seen_at TIMESTAMP NULL AFTER current_holder_id;

-- CC / Watchers — can view + get notified, but cannot act
CREATE TABLE letter_watchers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  letter_id INT NOT NULL,
  user_id INT NOT NULL,
  added_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (letter_id) REFERENCES letters(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_letter_watcher (letter_id, user_id)
) ENGINE=InnoDB;

-- Letter Templates — quick-start pre-filled drafts
CREATE TABLE letter_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  subject_template VARCHAR(255) NOT NULL,
  description_template TEXT NULL,
  category_id INT NULL,
  workflow_template_id INT NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES letter_categories(id) ON DELETE SET NULL,
  FOREIGN KEY (workflow_template_id) REFERENCES workflow_templates(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
