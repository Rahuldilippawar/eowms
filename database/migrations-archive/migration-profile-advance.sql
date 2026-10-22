-- ============================================================
-- Migration: Personal Profile — Advance Features
-- (Emergency Contact, Document Vault, Personal Achievements)
-- ============================================================

ALTER TABLE users ADD COLUMN emergency_contact_name VARCHAR(255) NULL AFTER mobile;
ALTER TABLE users ADD COLUMN emergency_contact_relation VARCHAR(100) NULL AFTER emergency_contact_name;
ALTER TABLE users ADD COLUMN emergency_contact_mobile VARCHAR(20) NULL AFTER emergency_contact_relation;

CREATE TABLE staff_documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  document_type VARCHAR(100) NOT NULL COMMENT 'उदा. Aadhar, PAN, Degree Certificate',
  file_path VARCHAR(255) NOT NULL,
  original_filename VARCHAR(255) NULL,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE staff_achievements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  achievement_date DATE NOT NULL,
  added_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
