-- ============================================================
-- Migration: About Page — देव/सद्गुरू/मार्गदर्शक विभाग + योगदानकर्ते
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

CREATE TABLE about_sections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  section_type ENUM('god','sadguru','guide') NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  photo_path VARCHAR(255) NULL,
  display_order INT DEFAULT 0,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE about_contributors (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  contribution VARCHAR(255) NULL COMMENT 'उदा. Concept व Requirement, Testing, इ.',
  bio TEXT NULL,
  photo_path VARCHAR(255) NULL,
  display_order INT DEFAULT 0,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB;
