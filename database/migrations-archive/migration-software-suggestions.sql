-- ============================================================
-- Migration: Software Suggestions/Feature Request Box —
-- Staff/HOD/Management यांना System मध्ये सुधारणा/नवीन कल्पना
-- सुचवता येण्यासाठी
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE software_suggestions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NOT NULL,
  category ENUM('new_feature','improvement','bug','other') DEFAULT 'improvement',
  module_name VARCHAR(150) NULL COMMENT 'कुठला Module संबंधित आहे',
  status ENUM('submitted','under_review','planned','implemented','rejected') DEFAULT 'submitted',
  admin_response TEXT NULL,
  responded_by INT NULL,
  responded_at TIMESTAMP NULL,
  submitted_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (responded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE software_suggestion_votes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  suggestion_id INT NOT NULL,
  user_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (suggestion_id) REFERENCES software_suggestions(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_vote (suggestion_id, user_id)
) ENGINE=InnoDB;

CREATE TABLE software_suggestion_comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  suggestion_id INT NOT NULL,
  comment TEXT NOT NULL,
  commented_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (suggestion_id) REFERENCES software_suggestions(id) ON DELETE CASCADE,
  FOREIGN KEY (commented_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
